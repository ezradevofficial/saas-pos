<?php

namespace Modules\Loyalty\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Modules\Loyalty\Ledgers\Ledgers;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\CreditEntry;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Models\LoyaltySale;
use Modules\Loyalty\Models\PointsEntry;
use Modules\Loyalty\Models\Programme;
use Modules\Loyalty\Pos\PosSales;
use Modules\Loyalty\Programmes;
use Modules\Loyalty\Tests\Concerns\BuildsLoyalty;
use Modules\POS\Models\Sale;
use RuntimeException;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// LOY-03..LOY-05, LOY-15, LOY-22, LOY-25, LOY-26, LOY-27: what POS sales
// mean for loyalty, through the till's real upload API. Device wins: a
// sale is never refused for loyalty reasons; what does not add up is
// posted and flagged.
class PosIntegrationTest extends TestCase
{
    use BuildsLoyalty, RefreshTenantDatabase;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLoyalty(['expiry_months' => 12]);
        $this->ranges()->assertOk();
        $this->ranges('pos.refund')->assertOk();
        $this->shift = $this->openShift();
    }

    private function loyaltySaleRow(string $id): LoyaltySale
    {
        return $this->inTenant(fn () => LoyaltySale::query()->findOrFail($id));
    }

    private function flags(string $saleId): array
    {
        return array_column($this->loyaltySaleRow($saleId)->flags ?? [], 'code');
    }

    public function test_a_sale_with_a_customer_earns_points_once_even_when_uploaded_twice(): void
    {
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id);

        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        // KES 1,125.00 at one point per KES 100.00: 11 points, expiring in 12 months.
        $this->assertSame('11', $this->pointsOf($this->amina->id));
        $entry = $this->inTenant(fn () => PointsEntry::query()->where('sale_id', $sale['id'])->sole());
        $this->assertSame('earn', $entry->kind);
        $this->assertTrue($entry->expires_at->equalTo($entry->occurred_at->addMonthsNoOverflow(12)));
        $this->assertSame('processed', $this->loyaltySaleRow($sale['id'])->status);
    }

    public function test_a_sale_without_a_customer_or_with_the_programme_off_earns_nothing(): void
    {
        $this->upload([$this->loyaltySale($this->shift, 1, null)])->assertOk();
        $this->inTenant(fn () => $this->programme->forceFill(['active' => false])->save());
        $this->upload([$this->loyaltySale($this->shift, 2, $this->amina->id)])->assertOk();

        $this->assertSame('0', $this->pointsOf($this->amina->id));
    }

    public function test_rbac_08_nothing_happens_while_the_module_is_off(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('loyalty'));
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id, [$this->pay('points', '112500', ['points' => '1125'])]);

        // The tender is stored as sent, unchecked; nothing is posted.
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored')->assertJsonPath('results.0.flags', []);
        $this->assertSame(0, $this->inTenant(fn () => LoyaltySale::query()->count()));
        $this->assertSame(['points' => '1125'], $this->inTenant(fn () => Sale::query()->findOrFail($sale['id'])->payments()->first()->tender));
    }

    public function test_a_void_reverses_points_earned_and_redeemed(): void
    {
        $this->inTenant(fn () => app(Ledgers::class)->points(
            app(Ledgers::class)->pointsAccount($this->acme->id, $this->amina->id), 'adjust', '500', ['source_type' => 'manual'],
        ));
        // KES 1,125.00: 200 points (KES 200.00) and KES 925.00 in cash; earns on KES 925.00: 9 points.
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id, [$this->pay('points', '20000', ['points' => '200']), $this->pay('cash_kes', '92500')]);
        $this->upload([$sale])->assertOk();
        $this->assertSame((string) (500 - 200 + 9), $this->pointsOf($this->amina->id));

        $this->voidSale($sale['id'])->assertOk()->assertJsonPath('results.0.void_status', 'applied');

        $this->assertSame('500', $this->pointsOf($this->amina->id));
        $kinds = $this->inTenant(fn () => PointsEntry::query()->where('sale_id', $sale['id'])->orderBy('created_at')->pluck('points', 'kind')->all());
        $this->assertEquals(['redeem' => '-200', 'earn' => '9', 'reverse_earn' => '-9', 'reverse_redeem' => '200'], $kinds);
    }

    public function test_refunds_reverse_earned_points_in_proportion(): void
    {
        // Four soaps, KES 2,250.00: 22 points.
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id, qty: '4');
        $this->upload([$sale])->assertOk();
        $this->assertSame('22', $this->pointsOf($this->amina->id));

        // One soap back (a quarter): floor(22 × 56,250 / 225,000) = 5.
        $this->refundSale($sale, $this->shift, '1', '56250', 1)->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->assertSame('17', $this->pointsOf($this->amina->id));

        // The rest back: everything earned is reversed, never more.
        $this->refundSale($sale, $this->shift, '3', '168750', 2)->assertOk();
        $this->assertSame('0', $this->pointsOf($this->amina->id));
    }

    public function test_points_redeemed_beyond_the_balance_offline_are_kept_and_flagged(): void
    {
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id, [$this->pay('points', '112500', ['points' => '1125'])]);

        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        $this->assertSame('-1125', $this->pointsOf($this->amina->id));
        $this->assertContains('points_overdrawn', $this->flags($sale['id']));
    }

    public function test_a_points_tender_that_does_not_add_up_is_flagged_on_the_pos_sale(): void
    {
        // 100 points are worth KES 100.00, not KES 1,125.00; no customer for the second.
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id, [$this->pay('points', '112500', ['points' => '100'])]);
        $flags = $this->upload([$sale])->assertOk()->json('results.0.flags');

        $this->assertContains(['code' => 'tender_flagged', 'detail' => ['payment' => 1, 'code' => 'points_value_mismatch']], $flags);

        $anonymous = $this->loyaltySale($this->shift, 2, null, [$this->pay('points', '112500', ['points' => '1125'])]);
        $this->assertContains(['code' => 'tender_flagged', 'detail' => ['payment' => 1, 'code' => 'points_no_customer']], $this->upload([$anonymous])->assertOk()->json('results.0.flags'));
        $this->assertContains('points_no_customer', $this->flags($anonymous['id']));
    }

    public function test_a_gift_card_pays_and_its_balance_drops(): void
    {
        [$card] = $this->giftCard('200000');
        $sale = $this->loyaltySale($this->shift, 1, null, [$this->pay('voucher', '112500', ['code_hash' => $card->code_hash])]);

        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.flags', []);

        $this->assertSame('87500', (string) $this->inTenant(fn () => GiftCard::query()->findOrFail($card->id)->balance_minor));
        $this->assertSame([], $this->flags($sale['id']));
    }

    public function test_gift_card_problems_are_flagged_never_refused(): void
    {
        [$card] = $this->giftCard('50000');
        $this->inTenant(fn () => GiftCard::query()->whereKey($card->id)->update(['status' => GiftCard::BLOCKED]));
        $over = $this->loyaltySale($this->shift, 1, null, [$this->pay('voucher', '112500', ['code_hash' => $card->code_hash])]);
        $unknown = $this->loyaltySale($this->shift, 2, null, [$this->pay('voucher', '112500', ['code_hash' => str_repeat('a', 64)])]);

        $this->upload([$over, $unknown])->assertOk()->assertJsonPath('results.0.status', 'stored')->assertJsonPath('results.1.status', 'stored');

        $this->assertSame('-62500', (string) $this->inTenant(fn () => GiftCard::query()->findOrFail($card->id)->balance_minor));
        $this->assertEqualsCanonicalizing(['gift_card_blocked', 'gift_card_overdrawn'], $this->flags($over['id']));
        $this->assertSame(['gift_card_unknown'], $this->flags($unknown['id']));
    }

    public function test_a_refund_to_a_gift_card_puts_the_money_back(): void
    {
        [$card] = $this->giftCard('200000');
        $sale = $this->loyaltySale($this->shift, 1, null, [$this->pay('voucher', '112500', ['code_hash' => $card->code_hash])]);
        $this->upload([$sale])->assertOk();

        $this->refundSale($sale, $this->shift, '1', '56250', 1, [$this->pay('voucher', '56250', ['code_hash' => $card->code_hash])])->assertOk();

        $this->assertSame('143750', (string) $this->inTenant(fn () => GiftCard::query()->findOrFail($card->id)->balance_minor));
    }

    public function test_a_sale_on_account_is_charged_and_a_void_reverses_it(): void
    {
        $this->creditAccount('500000');
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id, [$this->pay('credit', '112500')], extra: ['cashier_id' => $manager->id, 'actor_proof' => $this->actorProof($manager->id)]);

        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.flags', []);

        $account = $this->inTenant(fn () => Account::query()->where('kind', Account::CREDIT)->sole());
        $this->assertSame('112500', (string) $account->balance);
        $this->assertSame([], $this->flags($sale['id']));

        $this->voidSale($sale['id'])->assertOk();
        $this->assertSame('0', (string) $this->inTenant(fn () => Account::query()->findOrFail($account->id)->balance));
        $this->assertSame(['charge', 'reverse_charge'], $this->inTenant(fn () => CreditEntry::query()->orderBy('created_at')->pluck('kind')->all()));
    }

    public function test_a_credit_sale_over_the_limit_or_without_an_account_or_permission_is_kept_and_flagged(): void
    {
        $this->creditAccount('100000');
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id, [$this->pay('credit', '112500')], extra: ['cashier_id' => $cashier->id, 'actor_proof' => $this->actorProof($cashier->id)]);

        $flags = $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored')->json('results.0.flags');

        // The cashier template does not sell on credit (loyalty.credit.sell is the tenant's grant).
        $this->assertContains(['code' => 'tender_flagged', 'detail' => ['payment' => 1, 'code' => 'credit_not_permitted']], $flags);
        $this->assertEqualsCanonicalizing(['credit_not_permitted', 'credit_limit_exceeded'], $this->flags($sale['id']));
        $this->assertSame('112500', (string) $this->inTenant(fn () => Account::query()->where('kind', Account::CREDIT)->value('balance')));

        // Another customer with no credit account: the debt is still recorded, on an account opened for it.
        $other = $this->customer();
        $second = $this->loyaltySale($this->shift, 2, $other->id, [$this->pay('credit', '112500')]);
        $this->upload([$second])->assertOk();
        $this->assertContains('credit_no_account', $this->flags($second['id']));
        $this->assertSame('112500', (string) $this->inTenant(fn () => Account::query()->where('party_id', $other->id)->where('kind', Account::CREDIT)->value('balance')));
    }

    public function test_a_failure_in_loyalty_never_fails_the_pos_upload_and_is_reprocessed(): void
    {
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id);
        $this->app->instance(Programmes::class, new class extends Programmes
        {
            public function active(string $companyId): ?Programme
            {
                throw new RuntimeException('programme unavailable');
            }
        });

        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        $this->assertSame('failed', $this->loyaltySaleRow($sale['id'])->status);
        $this->assertSame('programme unavailable', $this->loyaltySaleRow($sale['id'])->error);

        $this->app->forgetInstance(Programmes::class);
        $this->assertTrue($this->inTenant(fn () => app(PosSales::class)->process($sale['id'])));

        $this->assertSame('processed', $this->loyaltySaleRow($sale['id'])->status);
        $this->assertSame('11', $this->pointsOf($this->amina->id));
    }
}
