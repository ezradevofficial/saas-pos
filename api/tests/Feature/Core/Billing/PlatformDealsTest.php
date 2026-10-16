<?php

namespace Tests\Feature\Core\Billing;

use App\Core\Audit\AuditEntry;
use App\Core\Billing\Models\BillingDeal;
use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformDatabase;
use Illuminate\Database\QueryException;
use Tests\Concerns\BuildsBilling;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * ADM-02 (docs/modules/platform-admin.md): custom Enterprise deals. Sales
 * or finance draft, finance approves (never the drafter); the deal prices
 * the tenant's quotes and invoices over its effective period, for that
 * tenant only; an approved deal is only ended, never edited.
 */
class PlatformDealsTest extends TestCase
{
    use BuildsBilling, CreatesPlatformStaff, RefreshTenantDatabase, ResetsPlatformTables;

    private const ENTERPRISE = ['modules' => ['inventory', 'pos'], 'quantities' => ['outlet' => 1], 'cycle' => 'monthly', 'bundle' => 'enterprise'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();
    }

    private function draft(array $body, ?PlatformUser $by = null, ?string $tenant = null): string
    {
        $tenant ??= $this->owner->tenant_id;

        return $this->postJson("/api/v1/platform/tenants/{$tenant}/deals", [
            'effective_from' => '2026-11-01',
            'reason' => 'Negotiated with the customer for a three-year term',
            ...$body,
        ], $this->staffBearer($by ?? $this->staff(PlatformUser::ROLE_SALES)))->assertCreated()->json('data.id');
    }

    private function approve(string $deal, ?string $tenant = null): void
    {
        $tenant ??= $this->owner->tenant_id;
        $this->postJson("/api/v1/platform/tenants/{$tenant}/deals/{$deal}/approve", ['note' => 'Within the discount policy'], $this->staffBearer($this->staff(PlatformUser::ROLE_FINANCE)))
            ->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_finance_approves_a_deal_drafted_by_someone_else_and_it_prices_quotes_and_invoices(): void
    {
        $sales = $this->staff(PlatformUser::ROLE_SALES);
        $deal = $this->draft(['lines' => [['type' => 'bundle', 'key' => 'starter', 'amount' => 300000]]], $sales);
        $tenant = $this->owner->tenant_id;

        // Pending: not yet in force. Sales cannot approve; a drafter in finance cannot approve their own.
        $this->postJson('/api/v1/subscription/quote', [...self::STARTER, 'cycle' => 'annual'], $this->headersFor())->assertOk()->assertJsonPath('data.new.monthly_subtotal', 390000);
        $this->postJson("/api/v1/platform/tenants/{$tenant}/deals/{$deal}/approve", [], $this->staffBearer($sales))->assertForbidden();
        $finance = $this->staff(PlatformUser::ROLE_FINANCE);
        $own = $this->draft(['lines' => [['type' => 'module', 'key' => 'payroll', 'amount' => 10000]], 'effective_from' => '2027-06-01'], $finance);
        $this->postJson("/api/v1/platform/tenants/{$tenant}/deals/{$own}/approve", [], $this->staffBearer($finance))->assertForbidden()->assertJsonPath('code', 'deal_own_draft');

        $this->approve($deal);

        // The quote and the first invoice use the deal's price, flagged on the line.
        $quote = $this->postJson('/api/v1/subscription/quote', [...self::STARTER, 'cycle' => 'annual'], $this->headersFor())->assertOk();
        $quote->assertJsonPath('data.new.monthly_subtotal', 300000)->assertJsonPath('data.new.lines.0.deal', true);
        $invoice = $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated();
        $this->assertSame(300000, $invoice->json('data.total.amount_minor'));
        $this->assertSame('deal:'.$deal, $this->subscription()->price_ids['bundle:starter']);
        $this->assertSame($deal, $this->getJson("/api/v1/platform/tenants/{$tenant}", $this->staffBearer($sales))->json('data.account.deal.id'));

        // Audited in both chains.
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'core.billing_deal.update')->exists()));
        $this->assertSame(['platform.deal.approve', 'platform.deal.draft'], PlatformDatabase::connection()->table('platform_audit_logs')->where('action', 'like', 'platform.deal.%')->where('after->deal_id', $deal)->orderByDesc('seq')->pluck('action')->all());
    }

    public function test_an_enterprise_deal_prices_the_quoted_bundle_for_that_tenant_only(): void
    {
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(Subscriptions::class)->startTrial('KE', self::STARTER));

        $this->postJson('/api/v1/subscription/quote', self::ENTERPRISE, $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'plan_quoted');

        $deal = $this->draft(['bundle' => 'enterprise', 'lines' => [['type' => 'bundle', 'key' => 'enterprise', 'amount' => 2500000, 'extra_outlet_amount' => 500000]]]);
        $this->approve($deal);

        $quote = $this->postJson('/api/v1/subscription/quote', [...self::ENTERPRISE, 'quantities' => ['outlet' => 3]], $this->headersFor())->assertOk();
        $quote->assertJsonPath('data.new.total', 3500000)->assertJsonPath('data.new.lines.0.deal', true);
        $this->postJson('/api/v1/subscription/change', [...self::ENTERPRISE, 'quantities' => ['outlet' => 3], 'expected_charge' => 0], $this->headersFor())->assertOk();
        $this->assertSame('enterprise', $this->subscription()->bundle);

        // Another tenant still sees Enterprise as quoted.
        $this->postJson('/api/v1/subscription/quote', self::ENTERPRISE, $this->headersFor($other['user']))->assertStatus(422)->assertJsonPath('code', 'plan_quoted');
        $this->getJson("/api/v1/platform/tenants/{$other['user']->tenant_id}/deals", $this->staffBearer($this->staff(PlatformUser::ROLE_FINANCE)))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_deal_takes_effect_on_its_date_and_renewals_price_with_it(): void
    {
        $deal = $this->draft(['effective_from' => '2026-12-01', 'lines' => [['type' => 'bundle', 'key' => 'starter', 'amount' => 250000]]]);
        $this->approve($deal);

        $this->activate();
        $this->assertSame(390000, $this->invoices()[0]->total_minor, 'November is priced at the catalogue');

        $this->cycle('2026-12-01 01:00:00');
        $renewal = collect($this->invoices())->firstWhere('kind', 'renewal');
        $this->assertSame(250000, $renewal->total_minor, 'the renewal on the deal date is priced with the deal');
        $this->assertSame(250000, $this->subscription()->period_amount_minor);
    }

    public function test_deals_never_overlap_and_an_approved_deal_is_only_ended(): void
    {
        $tenant = $this->owner->tenant_id;
        $first = $this->draft(['lines' => [['type' => 'bundle', 'key' => 'starter', 'amount' => 300000]]]);
        $this->approve($first);
        $second = $this->draft(['effective_from' => '2027-01-01', 'lines' => [['type' => 'bundle', 'key' => 'starter', 'amount' => 280000]]]);
        $finance = $this->staffBearer($this->staff(PlatformUser::ROLE_FINANCE));
        $this->postJson("/api/v1/platform/tenants/{$tenant}/deals/{$second}/approve", [], $finance)->assertStatus(422)->assertJsonPath('code', 'deal_overlaps');

        $this->postJson("/api/v1/platform/tenants/{$tenant}/deals/{$first}/end", ['last_day' => '2026-12-31'], $finance)->assertOk()
            ->assertJsonPath('data.status', 'ended')->assertJsonPath('data.effective_to', '2026-12-31');
        $this->postJson("/api/v1/platform/tenants/{$tenant}/deals/{$second}/approve", [], $finance)->assertOk();

        // Terms never change, for any role (trigger).
        $this->expectException(QueryException::class);
        $this->inTenant(fn () => BillingDeal::query()->whereKey($second)->toBase()->update(['lines' => json_encode([['type' => 'bundle', 'key' => 'starter', 'amount' => 1]])]));
    }

    public function test_deal_lines_are_validated_and_permissions_checked(): void
    {
        $tenant = $this->owner->tenant_id;
        $sales = $this->staffBearer($this->staff(PlatformUser::ROLE_SALES));
        $url = "/api/v1/platform/tenants/{$tenant}/deals";
        $base = ['effective_from' => '2026-11-01', 'reason' => 'Negotiated with the customer for a three-year term'];

        $this->postJson($url, [...$base, 'lines' => [['type' => 'bundle', 'key' => 'starter', 'amount' => 1], ['type' => 'bundle', 'key' => 'starter', 'amount' => 2]]], $sales)->assertStatus(422)->assertJsonValidationErrors('lines.1.key');
        $this->postJson($url, [...$base, 'lines' => [['type' => 'module', 'key' => 'nope', 'amount' => 1]]], $sales)->assertStatus(422)->assertJsonPath('code', 'deal_unknown_item');
        $this->postJson($url, [...$base, 'effective_from' => '2026-10-01', 'lines' => [['type' => 'module', 'key' => 'pos', 'amount' => 1]]], $sales)->assertStatus(422)->assertJsonValidationErrors('effective_from');
        $this->postJson($url, [...$base, 'lines' => [['type' => 'module', 'key' => 'pos', 'amount' => -5]]], $sales)->assertStatus(422);

        foreach ([PlatformUser::ROLE_SUPPORT, PlatformUser::ROLE_COMPLIANCE, PlatformUser::ROLE_ENGINEERING] as $role) {
            $this->postJson($url, [...$base, 'lines' => [['type' => 'module', 'key' => 'pos', 'amount' => 1]]], $this->staffBearer($this->staff($role)))->assertForbidden();
        }

        $this->getJson('/api/v1/platform/tenants/00000000-0000-7000-8000-000000000000/deals', $sales)->assertNotFound();
        $this->getJson($url, $this->headersFor())->assertUnauthorized();
    }
}
