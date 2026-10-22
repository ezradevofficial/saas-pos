<?php

namespace Tests\Feature\Core\Marketplace;

use App\Core\Billing\Models\BillingInvoice;
use App\Core\Marketplace\Connectors\FirstParty;
use App\Core\Marketplace\Models\MarketplacePayable;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformDatabase;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsBilling;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

// MKT-03 (ADR 024): paid apps are app lines of the subscription's invoices
// (free during the trial, pro-rated when installed in a paid period, on
// every renewal); when an invoice is paid the developer earns 80 % of the
// line net of discount and tax in the operator's ledger; staff pay out by
// hand with a reference, never more than the balance.
class PaidAppsTest extends TestCase
{
    use BuildsBilling, BuildsMarketplace, CreatesPlatformStaff, RefreshTenantDatabase, ResetsPlatformTables;

    private const PAID = ['model' => 'paid', 'monthly' => ['KES' => 50000, 'USD' => 500], 'yearly' => ['KES' => 500000]];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBilling();
    }

    private function install(array $app): string
    {
        $pkce = $this->pkce();

        return $this->approve($app['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $this->roles->get('admin')->id, 'scope_type' => 'tenant', 'scope_id' => null])
            ->assertOk()->json('data.installation_id');
    }

    /** @return list<array<string, mixed>> */
    private function appLines(string $invoiceId): array
    {
        $invoice = $this->inTenant(fn () => BillingInvoice::findOrFail($invoiceId));

        return array_values(array_filter($invoice->lines, fn (array $line) => $line['type'] === 'app'));
    }

    public function test_a_paid_app_is_free_in_the_trial_then_billed_and_earns_its_developer_80_percent(): void
    {
        $app = $this->listedApp(['pricing' => self::PAID]);
        $installation = $this->install($app);
        $this->getJson("/api/v1/apps/installed/{$installation}", $this->headersFor())->assertOk()->assertJsonPath('data.price.monthly_minor', 50000);
        $this->assertSame([], $this->invoices(), 'nothing is invoiced during the trial');

        $first = $this->activate();
        $lines = $this->appLines($first->id);
        $this->assertCount(1, $lines);
        $this->assertSame([$app['app_id'], 50000, 1], [$lines[0]['key'], $lines[0]['amount'], $lines[0]['months']]);

        $earning = $this->inOperator(fn () => MarketplacePayable::where('kind', 'earning')->sole());
        $base = $lines[0]['net_minor'] - (int) ($lines[0]['tax_minor'] ?? 0);
        $this->assertSame([FirstParty::DEVELOPER, 'KES', $base, intdiv(2 * $base * 80 + 100, 200)], [$earning->developer_id, $earning->currency, $earning->base_minor, $earning->amount_minor]);
        $this->assertSame($this->owner->tenant_id, $earning->customer_tenant_id);

        // The renewal carries the app again; an uninstalled app is not billed any more.
        $this->cycle('2026-12-01 09:00:00');
        $renewal = collect($this->invoices())->firstWhere('kind', 'renewal');
        $this->assertCount(1, $this->appLines($renewal->id));
        $this->postJson("/api/v1/apps/installed/{$installation}/uninstall", [], $this->headersFor())->assertOk();
        $this->cycle('2027-01-01 09:00:00');
        $next = collect($this->invoices())->where('kind', 'renewal')->last();
        $this->assertNotSame($renewal->id, $next->id);
        $this->assertSame([], $this->appLines($next->id));
    }

    public function test_installed_in_a_paid_period_the_rest_of_the_period_is_charged_now(): void
    {
        $this->activate();
        $this->travelToDay('2026-11-16 08:00:00');
        $app = $this->listedApp(['pricing' => self::PAID]);
        $this->install($app);

        $upgrade = collect($this->invoices())->firstWhere('kind', 'upgrade');
        $this->assertNotNull($upgrade);
        $line = $this->appLines($upgrade->id)[0];
        // 15 of the period's 30 days: half the monthly price.
        $this->assertSame([15, 30, 25000], [$line['quantity'], $line['period_days'], $line['amount']]);
    }

    public function test_a_price_in_another_currency_or_an_installer_who_cannot_pay_is_refused(): void
    {
        $usdOnly = $this->listedApp(['pricing' => ['model' => 'paid', 'monthly' => ['USD' => 500]]]);
        $pkce = $this->pkce();
        $this->approve($usdOnly['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $this->roles->get('admin')->id, 'scope_type' => 'tenant', 'scope_id' => null])
            ->assertUnprocessable()->assertJsonPath('code', 'app_price_unavailable');

        $paid = $this->listedApp(['pricing' => self::PAID]);
        $installer = $this->inTenant(function () {
            $role = $this->role('App installer', ['core.marketplace.view', 'core.marketplace.install', 'core.role.assign', 'core.item.view']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return [$user, $this->role('Reader', ['core.item.view'])];
        });
        $this->approve($paid['client_id'], $pkce['challenge'], $this->headersFor($installer[0]), ['role' => $installer[1]->id, 'scope_type' => 'tenant', 'scope_id' => null])
            ->assertForbidden()->assertJsonPath('code', 'app_payment_not_allowed');
        // A free app needs no subscription right.
        $free = $this->listedApp();
        $this->approve($free['client_id'], $pkce['challenge'], $this->headersFor($installer[0]), ['role' => $installer[1]->id, 'scope_type' => 'tenant', 'scope_id' => null])->assertOk();
    }

    public function test_staff_see_balances_and_record_payouts_up_to_the_balance(): void
    {
        $app = $this->listedApp(['pricing' => self::PAID]);
        $this->install($app);
        $this->activate();
        $finance = $this->staffBearer($this->staff(PlatformUser::ROLE_FINANCE));
        $owed = $this->inOperator(fn () => (int) MarketplacePayable::where('kind', 'earning')->sum('amount_minor'));

        $this->getJson('/api/v1/platform/marketplace/payouts', $finance)->assertOk()
            ->assertJsonPath('data.0.balance_minor', $owed)->assertJsonPath('data.0.developer.id', FirstParty::DEVELOPER);

        $uri = '/api/v1/platform/marketplace/developers/'.FirstParty::DEVELOPER.'/payouts';
        $this->postJson($uri, ['currency' => 'KES', 'amount_minor' => $owed + 1, 'reference' => 'BANK-001'], $finance)->assertUnprocessable()->assertJsonPath('code', 'payout_over_balance');
        $this->postJson($uri, ['currency' => 'KES', 'amount_minor' => $owed - 100, 'reference' => 'BANK-001'], $finance)->assertCreated()->assertJsonPath('data.kind', 'payout');

        $this->getJson('/api/v1/platform/marketplace/payouts', $finance)->assertOk()->assertJsonPath('data.0.balance_minor', 100);
        $this->getJson('/api/v1/platform/marketplace/developers/'.FirstParty::DEVELOPER.'/ledger', $finance)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(1, PlatformDatabase::connection()->table('platform_audit_logs')->where('action', 'platform.marketplace.payout')->count());

        $support = $this->staffBearer($this->staff(PlatformUser::ROLE_SUPPORT));
        $this->getJson('/api/v1/platform/marketplace/payouts', $support)->assertForbidden();
        $this->postJson($uri, ['currency' => 'KES', 'amount_minor' => 1, 'reference' => 'BANK-002'], $support)->assertForbidden();
    }
}
