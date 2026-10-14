<?php

namespace Modules\Loyalty\Tests;

use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\ModuleRegistry;
use Carbon\CarbonImmutable;
use Modules\Loyalty\GiftCards\GiftCards;
use Modules\Loyalty\Ledgers\Ledgers;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\PointsEntry;
use Modules\Loyalty\Points\PointsExpiry;
use Modules\Loyalty\Tests\Concerns\BuildsLoyalty;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// LOY-06, LOY-11, LOY-19, LOY-24, LOY-26, NFR-04: what the till pulls,
// points expiry (first in, first out) and the notifications.
class SyncAndExpiryTest extends TestCase
{
    use BuildsLoyalty, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLoyalty(['expiry_months' => 6, 'gift_card_low_minor' => '50000']);
    }

    private function pull(array $entities): array
    {
        return $this->getJson('/api/v1/sync/pull?'.http_build_query(['entities' => $entities]), $this->tillHeaders())->assertOk()->json('entities');
    }

    public function test_the_till_pulls_settings_promotions_members_and_gift_cards_without_codes(): void
    {
        [$card, $code] = $this->giftCard('100000');
        $this->creditAccount('500000');
        $this->postJson('/api/v1/loyalty/promotions', [
            'company_id' => $this->acme->id, 'name' => 'VIP', 'type' => 'percent', 'percent' => '10', 'customer_tags' => ['vip'], 'status' => 'active', 'requires_coupon' => true,
        ], $this->headersFor())->assertCreated();
        $this->postJson('/api/v1/loyalty/promotions', ['company_id' => $this->acme->id, 'name' => 'Branch B only', 'type' => 'percent', 'percent' => '5', 'branch_ids' => [$this->branchB->id], 'status' => 'active'], $this->headersFor())->assertCreated();

        $entities = $this->pull(['loyalty_settings', 'loyalty_promotions', 'loyalty_members', 'loyalty_gift_cards']);

        $this->assertSame(['id' => 'module', 'active' => true], $entities['loyalty_settings']['upserts'][0]);
        $this->assertSame('100', collect($entities['loyalty_settings']['upserts'])->firstWhere('id', 'programme')['point_value_minor']);
        $promotions = collect($entities['loyalty_promotions']['upserts']);
        $this->assertSame(['VIP'], $promotions->where('kind', 'promotion')->pluck('name')->all(), 'the other branch\'s promotion is not sent');
        $this->assertSame([], $promotions->firstWhere('kind', 'promotion')['coupon_codes']);
        $this->assertSame([$this->amina->id], $promotions->firstWhere('id', 'group:vip')['customer_ids']);
        $this->assertSame('KES', collect($entities['loyalty_members']['upserts'])->firstWhere('kind', 'credit')['currency']);
        $cards = $entities['loyalty_gift_cards']['upserts'];
        $this->assertSame($card->code_hash, $cards[0]['code_hash']);
        $this->assertStringNotContainsString(str_replace('-', '', $code), json_encode($entities));
    }

    public function test_without_a_points_programme_the_till_still_gets_the_module_row(): void
    {
        $this->inTenant(fn () => $this->programme->delete());

        $this->assertSame([['id' => 'module', 'active' => true]], $this->pull(['loyalty_settings'])['loyalty_settings']['upserts']);
    }

    public function test_rbac_08_entities_are_not_served_while_the_module_is_off(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('loyalty'));

        $this->assertArrayNotHasKey('loyalty_settings', $this->getJson('/api/v1/sync/pull', $this->tillHeaders())->assertOk()->json('entities'));
    }

    public function test_m6_points_given_back_never_expire_and_are_spent_last(): void
    {
        $ledgers = app(Ledgers::class);
        $this->inTenant(function () use ($ledgers) {
            $account = $ledgers->pointsAccount($this->acme->id, $this->amina->id);
            $ledgers->points($account, 'earn', '100', ['source_type' => 'manual', 'occurred_at' => now()->subMonths(7), 'expires_at' => now()->subMonth()]);
            // A redemption given back: a lot that never expires.
            $ledgers->points($account, 'reverse_redeem', '50', ['source_type' => 'pos_void', 'occurred_at' => now()->subMonths(6)]);
            // 60 spent: from the earned lot first, the 50 given back last.
            $ledgers->points($account, 'redeem', '-60', ['source_type' => 'manual', 'occurred_at' => now()->subMonths(5)]);
        });

        $this->assertSame('40', $this->inTenant(fn () => app(PointsExpiry::class)->run(CarbonImmutable::now())));
        $this->assertSame('50', $this->pointsOf($this->amina->id));
    }

    public function test_m6_a_reversed_earn_takes_its_own_sale_lot_not_the_oldest(): void
    {
        $ledgers = app(Ledgers::class);
        $sale = $this->id();
        $this->inTenant(function () use ($ledgers, $sale) {
            $account = $ledgers->pointsAccount($this->acme->id, $this->amina->id);
            $ledgers->points($account, 'earn', '100', ['source_type' => 'manual', 'occurred_at' => now()->subMonths(7), 'expires_at' => now()->subMonth()]);
            $ledgers->points($account, 'earn', '30', ['source_type' => 'pos_sale', 'sale_id' => $sale, 'occurred_at' => now()->subDays(3), 'expires_at' => now()->addMonths(6)]);
            $ledgers->points($account, 'reverse_earn', '-30', ['source_type' => 'pos_refund', 'sale_id' => $sale, 'occurred_at' => now()->subDay()]);
        });

        $this->assertSame('100', $this->inTenant(fn () => app(PointsExpiry::class)->run(CarbonImmutable::now())));
        $this->assertSame('0', $this->pointsOf($this->amina->id));
    }

    public function test_points_expire_first_in_first_out_and_managers_are_told_before(): void
    {
        $ledgers = app(Ledgers::class);
        $this->inTenant(function () use ($ledgers) {
            $account = $ledgers->pointsAccount($this->acme->id, $this->amina->id);
            $ledgers->points($account, 'earn', '100', ['source_type' => 'manual', 'occurred_at' => now()->subMonths(7), 'expires_at' => now()->subMonth()]);
            $ledgers->points($account, 'earn', '50', ['source_type' => 'manual', 'occurred_at' => now()->subMonths(5)->subDays(20), 'expires_at' => now()->addDays(10)]);
            // 30 redeemed: they used the oldest lot first.
            $ledgers->points($account, 'redeem', '-30', ['source_type' => 'manual']);
        });

        $expired = $this->inTenant(fn () => app(PointsExpiry::class)->run(CarbonImmutable::now()));

        $this->assertSame('70', $expired);
        $this->assertSame('50', $this->pointsOf($this->amina->id));
        // Running again expires nothing more.
        $this->assertSame('0', $this->inTenant(fn () => app(PointsExpiry::class)->run(CarbonImmutable::now())));
        $this->assertSame(1, $this->inTenant(fn () => PointsEntry::query()->where('kind', 'expire')->count()));

        // The 50 points due in 10 days are within the 14-day notice: the owner is told once.
        $this->assertSame(1, $this->inTenant(fn () => InAppNotification::query()->where('user_id', $this->owner->id)->where('event_type', 'loyalty.points.expiring')->count()));
    }

    public function test_credit_near_the_limit_and_a_low_gift_card_notify_managers(): void
    {
        $account = $this->creditAccount('100000');
        $this->postJson("/api/v1/loyalty/accounts/{$account->id}/charges", ['amount_minor' => '85000'], $this->headersFor())->assertCreated();

        [, $code] = $this->giftCard('100000');
        $this->ranges()->assertOk();
        $shift = $this->openShift();
        $this->upload([$this->loyaltySale($shift, 1, null, [$this->pay('voucher', '56250', ['code_hash' => GiftCards::proof($code)])], qty: '1')])->assertOk();

        $types = $this->inTenant(fn () => InAppNotification::query()->where('user_id', $this->owner->id)->pluck('event_type')->all());
        $this->assertContains('loyalty.credit.limit_near', $types);
        $this->assertContains('loyalty.gift_card.low', $types);
        $this->assertSame('85000', (string) $this->inTenant(fn () => Account::query()->findOrFail($account->id)->balance));
    }
}
