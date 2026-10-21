<?php

namespace Modules\Loyalty\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\DueTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Loyalty\GiftCards\GiftCards;
use Modules\Loyalty\Ledgers\Ledgers;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\CreditEntry;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Models\GiftCardEntry;
use Modules\Loyalty\Models\LoyaltySale;
use Modules\Loyalty\Models\PointsEntry;
use Modules\Loyalty\Models\Programme;
use Modules\Loyalty\Pos\PosSales;
use Modules\Loyalty\Programmes;
use Modules\Loyalty\Tests\Concerns\BuildsLoyalty;
use Modules\POS\Events\SaleRefunded;
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

        // The tender is stored as sent, unchecked, and flagged for review (device wins); nothing is posted.
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored')
            ->assertJsonPath('results.0.flags', [['code' => 'tender_flagged', 'detail' => ['payment' => 1, 'code' => 'module_inactive']]]);
        $this->assertSame(0, $this->inTenant(fn () => LoyaltySale::query()->count()));
        $this->assertSame(['points' => '1125'], $this->inTenant(fn () => Sale::query()->findOrFail($sale['id'])->payments()->first()->tender));
    }

    public function test_rbac_08_a_refund_to_a_loyalty_tender_after_the_module_is_switched_off_is_flagged(): void
    {
        [, $code] = $this->giftCard('200000');
        $sale = $this->loyaltySale($this->shift, 1, null);
        $this->upload([$sale])->assertOk();
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('loyalty'));

        $refund = $this->refundSale($sale, $this->shift, '1', '56250', 1, [$this->pay('voucher', '56250', ['code_hash' => GiftCards::proof($code)])]);

        $refund->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->assertContains(['code' => 'tender_flagged', 'detail' => ['payment' => 1, 'code' => 'module_inactive']], $refund->json('results.0.flags'));
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
        [$card, $code] = $this->giftCard('200000');
        $sale = $this->loyaltySale($this->shift, 1, null, [$this->pay('voucher', '112500', ['code_hash' => GiftCards::proof($code)])]);

        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.flags', []);

        $this->assertSame('87500', (string) $this->inTenant(fn () => GiftCard::query()->findOrFail($card->id)->balance_minor));
        $this->assertSame([], $this->flags($sale['id']));
    }

    public function test_gift_card_problems_are_flagged_never_refused(): void
    {
        [$card, $code] = $this->giftCard('50000');
        $this->inTenant(fn () => GiftCard::query()->whereKey($card->id)->update(['status' => GiftCard::BLOCKED]));
        $over = $this->loyaltySale($this->shift, 1, null, [$this->pay('voucher', '112500', ['code_hash' => GiftCards::proof($code)])]);
        $unknown = $this->loyaltySale($this->shift, 2, null, [$this->pay('voucher', '112500', ['code_hash' => str_repeat('a', 64)])]);

        $this->upload([$over, $unknown])->assertOk()->assertJsonPath('results.0.status', 'stored')->assertJsonPath('results.1.status', 'stored');

        $this->assertSame('-62500', (string) $this->inTenant(fn () => GiftCard::query()->findOrFail($card->id)->balance_minor));
        $this->assertEqualsCanonicalizing(['gift_card_blocked', 'gift_card_overdrawn'], $this->flags($over['id']));
        $this->assertSame(['gift_card_unknown'], $this->flags($unknown['id']));
    }

    public function test_a_gift_card_expires_on_the_companys_day_not_utcs(): void
    {
        // 00:30 on Tuesday 20 October 2026 in Nairobi; still Monday 19 October in UTC. The card's last day was the 19th.
        $this->travelTo(CarbonImmutable::parse('2026-10-19T21:30:00Z'));
        [$card] = $this->giftCard('200000');
        $this->inTenant(fn () => $card->forceFill(['expires_on' => '2026-10-19'])->save());

        $this->assertTrue($this->inTenant(fn () => GiftCard::query()->findOrFail($card->id)->isExpired()));
        $this->getJson("/api/v1/loyalty/gift-cards/{$card->id}", $this->headersFor())->assertOk()->assertJsonPath('data.expired', true);
    }

    public function test_a_till_sale_on_an_expired_gift_card_is_flagged_on_the_branchs_day_not_utcs(): void
    {
        // Sold at 00:25 on 20 October in Nairobi (still the 19th in UTC); the card's last day was the 19th. Flagged, never refused.
        [$card, $code] = $this->giftCard('200000');
        $this->inTenant(fn () => $card->forceFill(['expires_on' => '2026-10-19'])->save());
        $sale = $this->loyaltySale($this->shift, 1, null, [$this->pay('voucher', '112500', ['code_hash' => GiftCards::proof($code)])], extra: ['sold_at' => '2026-10-19T21:25:00Z']);

        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->assertContains('gift_card_expired', $this->flags($sale['id']));
    }

    public function test_a_refund_to_a_gift_card_puts_the_money_back(): void
    {
        [$card, $code] = $this->giftCard('200000');
        $sale = $this->loyaltySale($this->shift, 1, null, [$this->pay('voucher', '112500', ['code_hash' => GiftCards::proof($code)])]);
        $this->upload([$sale])->assertOk();

        $this->refundSale($sale, $this->shift, '1', '56250', 1, [$this->pay('voucher', '56250', ['code_hash' => GiftCards::proof($code)])])->assertOk();

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

    public function test_loy_03_reprocess_retries_with_backoff_then_fails_permanently_into_the_review_list(): void
    {
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id);
        $this->failingProgrammes();
        $this->upload([$sale])->assertOk();
        $row = fn () => $this->loyaltySaleRow($sale['id']);
        $this->assertSame(['failed', 1], [$row()->status, $row()->attempts]);
        $this->assertLessThan(2, abs($row()->next_attempt_at->diffInSeconds(now()->addMinutes(5))));

        // Not due yet: nothing is tried.
        $this->artisan('loyalty:reprocess')->assertSuccessful();
        $this->assertSame(1, $row()->attempts);

        // Each retry waits longer: 5 min, 15 min, 1 h, 6 h, 24 h.
        foreach ([300, 900, 3600, 21600, 86400] as $index => $wait) {
            $this->travel($wait + 1)->seconds();
            $this->artisan('loyalty:reprocess')->assertSuccessful();
            $this->assertSame($index + 2, $row()->attempts);
        }

        $this->assertSame('failed_permanently', $row()->status);
        $this->assertNull($row()->next_attempt_at);
        $this->assertContains(['code' => 'processing_failed', 'detail' => ['attempts' => 6]], $row()->flags);

        // Never tried again; it shows in the flagged-sales review.
        $this->travel(2)->days();
        $this->artisan('loyalty:reprocess')->assertSuccessful();
        $this->assertSame(6, $row()->attempts);
        $this->getJson('/api/v1/loyalty/sales?flag=processing_failed', $this->headersFor())->assertOk()->assertJsonPath('data.0.id', $sale['id']);
    }

    public function test_loy_03_reprocess_finds_its_tenants_through_due_tenants(): void
    {
        $this->mock(DueTenants::class)->shouldReceive('withLoyaltySalesToReprocess')->once()->andReturn([]);

        $this->artisan('loyalty:reprocess')->assertSuccessful();
    }

    public function test_module_schedules_are_registered_by_their_providers(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode("\n");

        foreach (['loyalty:expire-points', 'loyalty:reprocess', 'accounting:recurring'] as $command) {
            $this->assertStringContainsString($command, $commands);
        }
        $this->assertStringNotContainsString('loyalty:', (string) file_get_contents(base_path('routes/console.php')));
    }

    public function test_deadlock_safety_cards_and_accounts_are_locked_in_id_order_before_posting(): void
    {
        [$first, $firstCode] = $this->giftCard('50000');
        [$second, $secondCode] = $this->giftCard('100000');
        $this->assertLessThan(0, strcmp($first->id, $second->id));
        $this->creditAccount('500000');
        // The card with the larger id pays first.
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id, [
            $this->pay('voucher', '62500', ['code_hash' => GiftCards::proof($secondCode)]),
            $this->pay('voucher', '50000', ['code_hash' => GiftCards::proof($firstCode)]),
        ]);
        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks) {
            if (str_contains($query->sql, 'for update') && preg_match('/from "(loyalty_gift_cards|loyalty_accounts)"/', $query->sql, $table) === 1) {
                $locks[] = [$table[1], $query->sql];
            }
        });

        $this->upload([$sale])->assertOk();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        // Accounts first, then every card the sale touches, each set in one query ordered by id.
        $this->assertSame('loyalty_accounts', $locks[0][0]);
        $this->assertStringContainsString('order by "id"', $locks[0][1]);
        $firstCardLock = collect($locks)->firstWhere(0, 'loyalty_gift_cards');
        $this->assertStringContainsString('order by "id"', $firstCardLock[1]);
        $this->assertSame(['0', '37500'], $this->inTenant(fn () => [(string) $first->refresh()->balance_minor, (string) $second->refresh()->balance_minor]));
    }

    private function failingProgrammes(): void
    {
        $this->app->instance(Programmes::class, new class extends Programmes
        {
            public function active(string $companyId): ?Programme
            {
                throw new RuntimeException('programme unavailable');
            }
        });
    }

    public function test_h1_a_sale_voided_before_it_was_processed_posts_nothing_when_reprocessed(): void
    {
        $this->inTenant(fn () => app(Ledgers::class)->points(
            app(Ledgers::class)->pointsAccount($this->acme->id, $this->amina->id), 'adjust', '500', ['source_type' => 'manual'],
        ));
        // Paid in cash (the till's tender checks read the programme too): it would earn 11 points.
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id);
        $this->failingProgrammes();
        $this->upload([$sale])->assertOk();
        $this->assertSame('failed', $this->loyaltySaleRow($sale['id'])->status);

        $this->voidSale($sale['id'])->assertOk();
        $this->app->forgetInstance(Programmes::class);
        $this->assertTrue($this->inTenant(fn () => app(PosSales::class)->process($sale['id'])));

        $this->assertSame('500', $this->pointsOf($this->amina->id));
        $this->assertSame(0, $this->inTenant(fn () => PointsEntry::query()->where('sale_id', $sale['id'])->count()));
        $this->assertSame(['voided_before_processing'], $this->flags($sale['id']));
    }

    public function test_h1_a_refund_before_processing_is_applied_after_the_postings_and_never_twice(): void
    {
        $captured = null;
        Event::listen(SaleRefunded::class, function (SaleRefunded $event) use (&$captured) {
            $captured = $event;
        });
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id, qty: '4');
        $this->failingProgrammes();
        $this->upload([$sale])->assertOk();

        $this->refundSale($sale, $this->shift, '1', '56250', 1)->assertOk();
        $this->assertSame('0', $this->pointsOf($this->amina->id));
        $this->assertCount(1, $this->loyaltySaleRow($sale['id'])->pending_refunds);

        $this->app->forgetInstance(Programmes::class);
        $this->assertTrue($this->inTenant(fn () => app(PosSales::class)->process($sale['id'])));
        // 22 earned, then a quarter back: 5 reversed.
        $this->assertSame('17', $this->pointsOf($this->amina->id));
        $this->assertSame([], $this->loyaltySaleRow($sale['id'])->pending_refunds);

        // A replayed SaleRefunded changes nothing.
        $this->assertNotNull($captured);
        $this->inTenant(fn () => app(PosSales::class)->refunded($captured->refund));
        $this->assertSame('17', $this->pointsOf($this->amina->id));
        $this->assertSame('56250', (string) $this->loyaltySaleRow($sale['id'])->refunded_minor);
    }

    public function test_m2_a_gift_card_sold_at_the_till_loads_at_most_its_line_and_mismatches_are_flagged(): void
    {
        $this->inTenant(fn () => $this->programme->forceFill(['gift_card_item_id' => $this->soap->id])->save());
        [$card, $code] = $this->giftCard('100000', false);
        [$other, $otherCode] = $this->giftCard('100000', false);

        // KES 2,000.00 claimed on a KES 1,125.00 line: KES 1,125.00 is loaded, and flagged.
        $sale = $this->loyaltySale($this->shift, 1, null);
        $sale['extensions'] = ['loyalty' => ['gift_cards' => [['line_id' => $sale['lines'][0]['id'], 'code_hash' => GiftCards::proof($code), 'amount_minor' => '200000']]]];
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->assertSame('112500', (string) $this->inTenant(fn () => GiftCardEntry::query()->where('gift_card_id', $card->id)->where('sale_id', $sale['id'])->where('kind', 'load')->value('amount_minor')));
        $this->assertSame([['code' => 'gift_card_sale_mismatch', 'detail' => ['sold' => 1, 'reason' => 'amount']]], $this->loyaltySaleRow($sale['id'])->flags);

        // A line that is not this sale's: nothing is loaded.
        $wrong = $this->loyaltySale($this->shift, 2, null);
        $wrong['extensions'] = ['loyalty' => ['gift_cards' => [['line_id' => $this->id(), 'code_hash' => GiftCards::proof($otherCode), 'amount_minor' => '50000']]]];
        $this->upload([$wrong])->assertOk();
        $this->assertSame([['code' => 'gift_card_sale_mismatch', 'detail' => ['sold' => 1, 'reason' => 'line']]], $this->loyaltySaleRow($wrong['id'])->flags);
        $this->assertSame(0, $this->inTenant(fn () => GiftCardEntry::query()->where('gift_card_id', $other->id)->where('kind', 'load')->where('sale_id', $wrong['id'])->count()));

        // Malformed data is flagged, never a failure.
        $bad = $this->loyaltySale($this->shift, 3, null);
        $bad['extensions'] = ['loyalty' => ['gift_cards' => [['line_id' => $bad['lines'][0]['id'], 'amount_minor' => 'lots']]]];
        $this->upload([$bad])->assertOk();
        $this->assertSame('processed', $this->loyaltySaleRow($bad['id'])->status);
        $this->assertSame('malformed', $this->loyaltySaleRow($bad['id'])->flags[0]['detail']['reason']);
    }

    public function test_m8_a_tender_carrying_the_stored_hash_is_unknown_only_the_proof_pays(): void
    {
        [$card, $code] = $this->giftCard('200000');
        $sale = $this->loyaltySale($this->shift, 1, null, [$this->pay('voucher', '112500', ['code_hash' => $card->code_hash])]);

        $this->upload([$sale])->assertOk();

        $this->assertContains('gift_card_unknown', $this->flags($sale['id']));
        $this->assertSame('200000', (string) $this->inTenant(fn () => GiftCard::query()->findOrFail($card->id)->balance_minor));
        $this->assertSame(GiftCards::hash($code), $card->code_hash);
        $this->assertSame(hash('sha256', GiftCards::proof($code)), $card->code_hash);
    }
}
