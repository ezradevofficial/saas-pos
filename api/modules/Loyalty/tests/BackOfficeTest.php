<?php

namespace Modules\Loyalty\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\DocumentTemplates\DataSources;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Modules\Loyalty\Documents\StatementSource;
use Modules\Loyalty\GiftCards\GiftCards;
use Modules\Loyalty\Ledgers\Ledgers;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\Coupon;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Models\LoyaltySale;
use Modules\Loyalty\Models\PromotionVersion;
use Modules\Loyalty\Tests\Concerns\BuildsLoyalty;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// LOY-01, LOY-08, LOY-09, LOY-13..LOY-18, LOY-20..LOY-23, RBAC-04,
// RBAC-06, RBAC-08: the back-office API, with permission-denied and
// other-tenant cases.
class BackOfficeTest extends TestCase
{
    use BuildsLoyalty, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLoyalty();
    }

    private function promotionBody(array $overrides = []): array
    {
        return array_replace([
            'company_id' => $this->acme->id, 'name' => 'Happy hour', 'type' => 'percent', 'percent' => '25', 'priority' => 5,
            'schedule' => ['days' => [1, 2, 3, 4, 5], 'time_from' => '17:00', 'time_to' => '19:00'], 'status' => 'active',
        ], $overrides);
    }

    public function test_the_programme_shows_defaults_then_saves_and_audits(): void
    {
        $this->inTenant(fn () => $this->programme->delete());

        $this->getJson("/api/v1/companies/{$this->acme->id}/loyalty-programme", $this->headersFor())->assertOk()
            ->assertJsonPath('data.saved', false)->assertJsonPath('data.currency', 'KES')->assertJsonPath('data.earn_spend_minor', '10000');

        $this->putJson("/api/v1/companies/{$this->acme->id}/loyalty-programme", ['active' => true, 'expiry_months' => 6, 'point_value_minor' => '50'], $this->headersFor())
            ->assertOk()->assertJsonPath('data.saved', true)->assertJsonPath('data.expiry_months', 6)->assertJsonPath('data.point_value_minor', '50');

        $this->putJson("/api/v1/companies/{$this->acme->id}/loyalty-programme", ['earn_spend_minor' => 1250], $this->headersFor())->assertUnprocessable();
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'loyalty.programme.create')->exists()));
    }

    public function test_loy_21_the_gift_card_item_is_a_service_or_non_stock_item(): void
    {
        $uri = "/api/v1/companies/{$this->acme->id}/loyalty-programme";

        // The soap is a stock item: refused.
        $this->putJson($uri, ['gift_card_item_id' => $this->soap->id], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['gift_card_item_id' => __('loyalty.validation.gift_card_item_type')]);

        foreach (['service', 'non_stock'] as $type) {
            $item = $this->inTenant(fn () => Item::create(['code' => 'GC-'.$type, 'name' => 'Gift card '.$type, 'type' => $type, 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id]));
            $this->putJson($uri, ['gift_card_item_id' => $item->id], $this->headersFor())->assertOk()->assertJsonPath('data.gift_card_item_id', $item->id);
        }
    }

    public function test_promotions_are_versioned_on_every_change(): void
    {
        $id = $this->postJson('/api/v1/loyalty/promotions', $this->promotionBody(), $this->headersFor())->assertCreated()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.percent', '25')->json('data.id');

        $this->patchJson("/api/v1/loyalty/promotions/{$id}", ['percent' => '30'], $this->headersFor())->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson("/api/v1/loyalty/promotions/{$id}", ['percent' => '30'], $this->headersFor())->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson("/api/v1/loyalty/promotions/{$id}/archive", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'archived')->assertJsonPath('data.version', 3);

        $this->assertSame([1, 2, 3], $this->inTenant(fn () => PromotionVersion::query()->where('promotion_id', $id)->orderBy('version')->pluck('version')->all()));
        $this->getJson('/api/v1/loyalty/promotions?status=archived', $this->headersFor())->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/loyalty/promotions?format=csv', $this->headersFor())->assertOk();
    }

    public function test_a_promotion_is_checked_for_its_type_and_company(): void
    {
        $this->postJson('/api/v1/loyalty/promotions', $this->promotionBody(['type' => 'bundle', 'percent' => null]), $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['amount_minor', 'currency', 'components']);
        $this->postJson('/api/v1/loyalty/promotions', $this->promotionBody(['percent' => '120']), $this->headersFor())->assertJsonValidationErrors('percent');

        $other = $this->otherTenant();
        $this->postJson('/api/v1/loyalty/promotions', $this->promotionBody(['company_id' => $other['company']->id]), $this->headersFor())->assertJsonValidationErrors('company_id');
    }

    public function test_coupons_are_unique_per_company_and_generated(): void
    {
        $id = $this->postJson('/api/v1/loyalty/promotions', $this->promotionBody(['requires_coupon' => true]), $this->headersFor())->json('data.id');

        $this->postJson("/api/v1/loyalty/promotions/{$id}/coupons", ['codes' => ['save 10'], 'generate' => 3, 'max_uses' => 1], $this->headersFor())
            ->assertCreated()->assertJsonCount(4, 'data')->assertJsonPath('data.0.code', 'SAVE10')->assertJsonPath('data.0.remaining', 1);
        $this->postJson("/api/v1/loyalty/promotions/{$id}/coupons", ['codes' => ['SAVE10']], $this->headersFor())->assertJsonValidationErrors('codes');
        $coupon = $this->inTenant(fn () => Coupon::query()->where('code', 'SAVE10')->firstOrFail());
        $this->postJson("/api/v1/loyalty/coupons/{$coupon->id}/archive", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/loyalty/coupons/{$coupon->id}/archive", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'coupon_archived');
    }

    public function test_the_preview_runs_the_engine_on_a_sample_cart(): void
    {
        $this->inTenant(fn () => $this->branchA->forceFill(['timezone' => 'Africa/Nairobi'])->save());

        $this->postJson('/api/v1/loyalty/promotions/preview', [
            'company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'currency' => 'KES', 'at' => '2026-10-13T14:30:00Z',
            'promotion' => ['type' => 'percent', 'percent' => '25', 'schedule' => ['days' => [2], 'time_from' => '17:00', 'time_to' => '19:00']],
            'lines' => [['item_id' => $this->soap->id, 'qty' => '2', 'unit_price_minor' => '56250']],
        ], $this->headersFor())->assertOk()
            // 14:30 UTC is 17:30 in Nairobi on a Tuesday: in the window.
            ->assertJsonPath('data.total_discount_minor', '28125')->assertJsonPath('data.lines.0.promotion_id', 'draft');
    }

    public function test_the_server_re_evaluates_promotions_on_upload_and_flags_differences(): void
    {
        $this->inTenant(fn () => $this->branchA->forceFill(['timezone' => 'UTC'])->save());
        $id = $this->postJson('/api/v1/loyalty/promotions', $this->promotionBody(['schedule' => []]), $this->headersFor())->json('data.id');
        // Sales are dated 5 minutes back: the promotion was in force by then.
        $this->travel(10)->minutes();
        $this->ranges()->assertOk();
        $shift = $this->openShift();

        // The till applied 25 % (KES 281.25) on the line, vouched by the promotion: no manual discount flag.
        $line = $this->line(['discount_minor' => '28125', 'tax_minor' => '9375', 'total_minor' => '84375', 'adjustments' => [['source' => 'loyalty.promotion', 'reference' => $id, 'amount_minor' => '28125']]]);
        $good = $this->saleBody($shift, 1, ['lines' => [$line], 'payments' => [$this->pay('cash_kes', '84375')]]);
        $this->upload([$good])->assertOk()->assertJsonPath('results.0.flags', []);

        // The same line claimed at KES 300.00 differs.
        $bad = $this->saleBody($shift, 2, ['lines' => [$this->line(['discount_minor' => '30000', 'tax_minor' => '9167', 'total_minor' => '82500', 'adjustments' => [['source' => 'loyalty.promotion', 'reference' => $id, 'amount_minor' => '30000']]])],
            'payments' => [$this->pay('cash_kes', '82500')]]);
        $this->upload([$bad])->assertOk();

        $flags = $this->inTenant(fn () => LoyaltySale::query()->findOrFail($bad['id'])->flags);
        $this->assertSame('promotion_differs', $flags[0]['code']);
        $this->assertSame('28125', $flags[0]['detail']['expected_minor']);
        $this->assertSame([], $this->inTenant(fn () => LoyaltySale::query()->findOrFail($good['id'])->flags));
    }

    public function test_h2_only_what_the_promotion_engine_confirms_is_vouched_the_rest_needs_the_discount_right(): void
    {
        $this->inTenant(fn () => $this->branchA->forceFill(['timezone' => 'UTC'])->save());
        $id = $this->postJson('/api/v1/loyalty/promotions', $this->promotionBody(['schedule' => []]), $this->headersFor())->json('data.id');
        $this->travel(10)->minutes();
        $this->ranges()->assertOk();
        $shift = $this->openShift();
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $asCashier = ['cashier_id' => $cashier->id, 'actor_proof' => $this->actorProof($cashier->id)];

        // The engine gives KES 281.25; the till claims KES 300.00 as the promotion's, by a cashier
        // without pos.discount.give: the KES 18.75 above it is an unauthorised manual discount.
        $bad = $this->saleBody($shift, 1, ['lines' => [$this->line(['discount_minor' => '30000', 'tax_minor' => '9167', 'total_minor' => '82500', 'adjustments' => [['source' => 'loyalty.promotion', 'reference' => $id, 'amount_minor' => '30000']]])],
            'payments' => [$this->pay('cash_kes', '82500')], ...$asCashier]);
        $flags = $this->upload([$bad])->assertOk()->assertJsonPath('results.0.status', 'stored')->json('results.0.flags');

        $this->assertContains('discount_unauthorised', array_column($flags, 'code'));
        $audit = $this->inTenant(fn () => AuditEntry::query()->where('action', 'pos.sale.discount')->where('auditable_id', $bad['id'])->sole());
        $this->assertFalse($audit->after['allowed']);
        $this->assertSame('28125', $audit->after['vouched_minor']);

        // What the engine confirms is vouched for the same cashier: no flag.
        $good = $this->saleBody($shift, 2, ['lines' => [$this->line(['discount_minor' => '28125', 'tax_minor' => '9375', 'total_minor' => '84375', 'adjustments' => [['source' => 'loyalty.promotion', 'reference' => $id, 'amount_minor' => '28125']]])],
            'payments' => [$this->pay('cash_kes', '84375')], ...$asCashier]);
        $this->upload([$good])->assertOk()->assertJsonPath('results.0.flags', []);

        // Loyalty flagged the difference too; the review list shows it to those who may see it.
        $this->getJson('/api/v1/loyalty/sales', $this->headersFor())->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $bad['id'])->assertJsonPath('data.0.flags.0.code', 'promotion_differs');
        $this->getJson('/api/v1/loyalty/sales?flagged=0', $this->headersFor())->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/loyalty/sales?flag=points_overdrawn', $this->headersFor())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/loyalty/sales?format=csv', $this->headersFor())->assertOk();
        $this->getJson('/api/v1/loyalty/sales', $this->headersFor($cashier))->assertForbidden();
        $accountant = $this->userWith('accountant', Scope::company($this->acme->id));
        $this->getJson('/api/v1/loyalty/sales', $this->headersFor($accountant))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_back_office_charge_sent_twice_with_its_key_is_recorded_once(): void
    {
        $account = $this->creditAccount('500000');
        $key = $this->id();

        $first = $this->postJson("/api/v1/loyalty/accounts/{$account->id}/charges", ['id' => $key, 'amount_minor' => '80000'], $this->headersFor())->assertCreated()->json('data.id');
        $again = $this->postJson("/api/v1/loyalty/accounts/{$account->id}/charges", ['id' => $key, 'amount_minor' => '80000'], $this->headersFor())->assertSuccessful()->json('data.id');

        $this->assertSame($first, $again);
        $this->assertSame('80000', (string) $this->inTenant(fn () => Account::query()->findOrFail($account->id)->balance));
        $this->postJson("/api/v1/loyalty/accounts/{$account->id}/charges", ['id' => 'not-a-uuid', 'amount_minor' => '1'], $this->headersFor())->assertJsonValidationErrors('id');
    }

    public function test_gift_card_lookups_are_throttled(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/v1/loyalty/gift-cards/lookup', ['code' => 'NOPE-NOPE'], $this->headersFor())->assertNotFound();
        }

        $this->postJson('/api/v1/loyalty/gift-cards/lookup', ['code' => 'NOPE-NOPE'], $this->headersFor())->assertTooManyRequests();
    }

    public function test_a_back_office_charge_over_the_credit_limit_is_refused_unless_the_override_covers_it(): void
    {
        $account = $this->creditAccount('100000');
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $this->postJson("/api/v1/loyalty/accounts/{$account->id}/charges", ['amount_minor' => '80000', 'reference' => 'INV-1'], $this->headersFor($manager))->assertCreated();
        $this->postJson("/api/v1/loyalty/accounts/{$account->id}/charges", ['amount_minor' => '30000'], $this->headersFor($manager))
            ->assertUnprocessable()->assertJsonPath('code', 'credit_limit_exceeded');

        // RBAC-06: KES 100.00 of override covers the KES 100.00 excess, but only held at the
        // account's company (M7): a branch-scoped role's override does not reach it.
        $this->inTenant(fn () => LimitRule::create(['role_id' => $this->roles->get('branch_manager')->id, 'key' => 'credit_limit_override', 'value' => '100']));
        $this->postJson("/api/v1/loyalty/accounts/{$account->id}/charges", ['amount_minor' => '30000'], $this->headersFor($manager))
            ->assertUnprocessable()->assertJsonPath('code', 'credit_limit_exceeded');

        $companyManager = $this->userWith('branch_manager', Scope::company($this->acme->id));
        $this->postJson("/api/v1/loyalty/accounts/{$account->id}/charges", ['amount_minor' => '30000'], $this->headersFor($companyManager))->assertCreated()
            ->assertJsonPath('data.flags.0.code', 'credit_limit_overridden');
        $this->assertSame('110000', (string) $this->inTenant(fn () => Account::query()->findOrFail($account->id)->balance));
    }

    public function test_accounts_open_take_payments_close_and_print_statements_and_ageing(): void
    {
        $this->amina->forceFill(['credit_limit_minor' => '300000', 'credit_limit_currency' => 'KES']);
        $this->inTenant(fn () => $this->amina->saveQuietly());
        $id = $this->postJson('/api/v1/loyalty/accounts', ['party_id' => $this->amina->id, 'company_id' => $this->acme->id], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.currency', 'KES')->assertJsonPath('data.credit_limit.amount_minor', '300000')->json('data.id');
        $this->postJson('/api/v1/loyalty/accounts', ['party_id' => $this->amina->id, 'company_id' => $this->acme->id], $this->headersFor())->assertJsonPath('code', 'credit_account_exists');

        // A sale on account 75 days ago (as the till's would be posted).
        $this->inTenant(fn () => app(Ledgers::class)->credit(Account::query()->findOrFail($id), 'charge', '100000', ['source_type' => 'manual', 'occurred_at' => now()->subDays(75)]));
        $this->postJson("/api/v1/loyalty/accounts/{$id}/charges", ['amount_minor' => '50000'], $this->headersFor())->assertCreated();
        $this->postJson("/api/v1/loyalty/accounts/{$id}/payments", ['amount_minor' => '40000', 'reference' => 'MPESA-1'], $this->headersFor())->assertCreated();

        // FIFO: the payment settles part of the 75-day charge.
        $this->getJson("/api/v1/loyalty/accounts/{$id}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.ageing.buckets.61_90', '60000')->assertJsonPath('data.ageing.buckets.0_30', '50000')->assertJsonPath('data.entries.0.balance.amount_minor', '110000');
        $this->getJson('/api/v1/loyalty/ageing', $this->headersFor())->assertOk()->assertJsonPath('data.0.ageing.open_minor', '110000');
        $this->getJson('/api/v1/loyalty/ageing?format=csv', $this->headersFor())->assertOk();

        $statement = $this->postJson("/api/v1/loyalty/accounts/{$id}/statements", ['from' => now('Africa/Nairobi')->subDays(30)->format('Y-m-d'), 'to' => now('Africa/Nairobi')->format('Y-m-d')], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.opening.amount_minor', '100000')->assertJsonPath('data.closing.amount_minor', '110000')->assertJsonCount(2, 'data.lines');
        $this->assertMatchesRegularExpression('/^ST-\d{4}-00001$/', $statement->json('data.number'));
        $pdf = $this->get("/api/v1/loyalty/statements/{$statement->json('data.id')}/pdf", $this->headersFor())->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        // RBAC-08: the statement's live source prints nothing while the module is off.
        $source = app(DataSources::class)->get(StatementSource::TYPE);
        $this->assertNotNull($this->inTenant(fn () => $source->load($statement->json('data.id'))));
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('loyalty'));
        $this->assertNull($this->inTenant(fn () => $source->load($statement->json('data.id'))));
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('loyalty'));

        $this->postJson("/api/v1/loyalty/accounts/{$id}/close", [], $this->headersFor())->assertJsonPath('code', 'credit_balance_not_zero');
        $this->postJson("/api/v1/loyalty/accounts/{$id}/adjustments", ['amount_minor' => '-110000', 'reason' => 'Written off'], $this->headersFor())->assertCreated();
        $this->postJson("/api/v1/loyalty/accounts/{$id}/close", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_gift_cards_issue_once_sell_block_and_look_up(): void
    {
        $issued = $this->postJson('/api/v1/loyalty/gift-cards', ['company_id' => $this->acme->id, 'kind' => 'gift_card', 'currency' => 'KES', 'amount_minor' => '250000', 'count' => 2], $this->headersFor())
            ->assertCreated()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'inactive');
        $code = $issued->json('data.0.code');
        $id = $issued->json('data.0.id');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $code);

        // The code is never shown again.
        $this->getJson("/api/v1/loyalty/gift-cards/{$id}", $this->headersFor())->assertOk()->assertJsonMissingPath('data.code')->assertJsonPath('data.last4', substr(str_replace('-', '', $code), -4));
        $this->assertStringNotContainsString($code, $this->getJson('/api/v1/loyalty/gift-cards', $this->headersFor())->getContent());

        $this->postJson("/api/v1/loyalty/gift-cards/{$id}/sell", ['reference' => 'CASH-9'], $this->headersFor())->assertOk()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.balance.amount_minor', '250000');
        $this->postJson("/api/v1/loyalty/gift-cards/{$id}/sell", [], $this->headersFor())->assertJsonPath('code', 'gift_card_not_inactive');
        $this->postJson('/api/v1/loyalty/gift-cards/lookup', ['code' => strtolower($code)], $this->headersFor())->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/loyalty/gift-cards/lookup', ['code' => 'NOPE-NOPE'], $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/loyalty/gift-cards/{$id}/block", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'blocked');
        $this->postJson("/api/v1/loyalty/gift-cards/{$id}/unblock", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'active');

        // LOY-23: the till checks a card of its company by hash.
        $this->postJson('/api/v1/loyalty/device/gift-cards/lookup', ['code_hash' => GiftCards::proof($code)], $this->tillHeaders())->assertOk()->assertJsonPath('data.balance_minor', '250000');
        $this->postJson('/api/v1/loyalty/device/gift-cards/lookup', ['code_hash' => str_repeat('b', 64)], $this->tillHeaders())->assertNotFound();
    }

    public function test_the_customer_tab_shows_accounts_and_takes_adjustments(): void
    {
        $this->creditAccount('500000');
        $this->postJson("/api/v1/loyalty/customers/{$this->amina->id}/points/adjust", ['company_id' => $this->acme->id, 'points' => '150', 'reason' => 'Welcome'], $this->headersFor())->assertCreated();

        $data = $this->getJson("/api/v1/loyalty/customers/{$this->amina->id}", $this->headersFor())->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['points', 'credit'], array_column($data['accounts'], 'kind'));
        $this->assertSame('150', collect($data['accounts'])->firstWhere('kind', 'points')['points']);
        $this->assertSame(['vip'], $data['party']['tags']);
    }

    public function test_permission_denied_cases(): void
    {
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $account = $this->creditAccount();
        [$card] = $this->giftCard();
        $promotion = $this->postJson('/api/v1/loyalty/promotions', $this->promotionBody(), $this->headersFor())->json('data.id');

        $this->postJson('/api/v1/loyalty/promotions', $this->promotionBody(), $this->headersFor($cashier))->assertForbidden();
        $this->getJson('/api/v1/loyalty/promotions', $this->headersFor($cashier))->assertForbidden();
        $this->getJson("/api/v1/loyalty/promotions/{$promotion}", $this->headersFor($cashier))->assertNotFound();
        $this->putJson("/api/v1/companies/{$this->acme->id}/loyalty-programme", ['active' => false], $this->headersFor($cashier))->assertNotFound();
        $this->getJson("/api/v1/loyalty/accounts/{$account->id}", $this->headersFor($cashier))->assertNotFound();
        $this->postJson("/api/v1/loyalty/accounts/{$account->id}/payments", ['amount_minor' => '100'], $this->headersFor($cashier))->assertNotFound();
        // The cashier sees cards (balance checks) but cannot sell or block them.
        $this->getJson("/api/v1/loyalty/gift-cards/{$card->id}", $this->headersFor($cashier))->assertOk();
        $this->postJson("/api/v1/loyalty/gift-cards/{$card->id}/block", [], $this->headersFor($cashier))->assertForbidden();
        $this->postJson('/api/v1/loyalty/gift-cards', ['company_id' => $this->acme->id, 'kind' => 'voucher', 'currency' => 'KES', 'amount_minor' => '100'], $this->headersFor($cashier))->assertForbidden();
        $this->postJson("/api/v1/loyalty/customers/{$this->amina->id}/points/adjust", ['company_id' => $this->acme->id, 'points' => '5', 'reason' => 'x'], $this->headersFor($cashier))->assertForbidden();

        // An accountant receives payments but does not sell cards.
        $accountant = $this->userWith('accountant', Scope::company($this->acme->id));
        $this->postJson("/api/v1/loyalty/accounts/{$account->id}/payments", ['amount_minor' => '100'], $this->headersFor($accountant))->assertCreated();
        $this->postJson("/api/v1/loyalty/gift-cards/{$card->id}/sell", [], $this->headersFor($accountant))->assertForbidden();

        // RBAC-08: with the module off, every route answers module_inactive.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('loyalty'));
        $this->getJson('/api/v1/loyalty/promotions', $this->headersFor())->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }

    public function test_another_tenants_records_are_not_found(): void
    {
        $account = $this->creditAccount();
        [$card, $code] = $this->giftCard();
        $promotion = $this->postJson('/api/v1/loyalty/promotions', $this->promotionBody(), $this->headersFor())->json('data.id');
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('loyalty'));
        $headers = $this->headersFor($other['user']);

        $this->getJson("/api/v1/loyalty/promotions/{$promotion}", $headers)->assertNotFound();
        $this->getJson("/api/v1/loyalty/accounts/{$account->id}", $headers)->assertNotFound();
        $this->postJson("/api/v1/loyalty/accounts/{$account->id}/charges", ['amount_minor' => '100'], $headers)->assertNotFound();
        $this->getJson("/api/v1/loyalty/gift-cards/{$card->id}", $headers)->assertNotFound();
        $this->postJson('/api/v1/loyalty/gift-cards/lookup', ['code' => $code], $headers)->assertNotFound();
        $this->getJson("/api/v1/loyalty/customers/{$this->amina->id}", $headers)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/loyalty/promotions', $headers)->assertOk()->json('data'));
        $this->postJson('/api/v1/loyalty/accounts', ['party_id' => $this->amina->id, 'company_id' => $other['company']->id], $headers)->assertJsonValidationErrors('party_id');
        $this->assertSame(1, $this->inTenant(fn () => GiftCard::query()->count()));
    }
}
