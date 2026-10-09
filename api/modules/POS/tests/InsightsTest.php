<?php

namespace Modules\POS\Tests;

use App\Core\Currency\Models\CompanyCurrency;
use App\Core\Currency\Models\ExchangeRate;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// TEN-07: consolidated sales for a period, scoped by the user's locations
// (RBAC-04), in each company's base currency and a reporting currency
// (CUR-04), as minor-unit strings, with payments and top items.
class InsightsTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPos();
        $this->ranges()->assertOk();
        $shiftA = $this->openShift();
        $this->upload([
            $this->saleBody($shiftA, 1),
            // Two soaps paid partly by M-Pesa, with KES 100.00 change in cash.
            $this->saleBody($shiftA, 2, ['payments' => [
                ['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => '22500', 'amount_in_sale_minor' => '22500', 'rate' => null, 'status' => 'confirmed'],
                ['id' => $this->id(), 'payment_method_id' => $this->methods['mpesa']->id, 'currency' => 'KES', 'amount_minor' => '100000', 'amount_in_sale_minor' => '100000', 'rate' => null, 'status' => 'confirmed'],
            ], 'change' => ['currency' => 'KES', 'amount_minor' => '10000', 'rate' => null]]),
        ])->assertOk();

        [, $tokenB] = $this->pairedTill($this->locationB, 'Till B');
        $this->ranges(token: $tokenB)->assertOk();
        $shiftB = $this->openShift($tokenB);
        $this->upload([$this->saleBody($shiftB, 501, ['receipt_number' => 'R-L02-000501'])], $tokenB)->assertOk();

        $this->today = now($this->acme->timezone)->toDateString();
    }

    private function insights(array $query = [], $user = null)
    {
        return $this->getJson('/api/v1/pos/insights?'.http_build_query(['from' => $this->today, 'to' => $this->today, ...$query]), $this->headersFor($user));
    }

    public function test_totals_by_company_branch_and_location_in_base_currency(): void
    {
        $data = $this->insights()->assertOk()->json('data');

        $this->assertSame(3, $data['sales_count']);
        $this->assertSame('KES', $data['reporting_currency']);
        $this->assertSame(['amount_minor' => '337500', 'currency' => 'KES'], $data['consolidated']['total']);
        $this->assertSame(['amount_minor' => '112500', 'currency' => 'KES'], $data['consolidated']['average_ticket']);
        $this->assertTrue($data['consolidated']['complete']);

        $acme = $data['companies'][0];
        $this->assertSame($this->acme->id, $acme['company']['id']);
        $this->assertSame(3, $acme['sales_count']);
        $this->assertSame(['amount_minor' => '337500', 'currency' => 'KES'], $acme['total']);
        $this->assertSame(['amount_minor' => '37500', 'currency' => 'KES'], $acme['tax']);
        $this->assertSame([2, 1], array_column($acme['branches'], 'sales_count'));
        $this->assertSame('Outlet A', $acme['branches'][0]['locations'][0]['location']['name']);
        $this->assertSame(['amount_minor' => '225000', 'currency' => 'KES'], $acme['branches'][0]['locations'][0]['total']);

        $this->assertSame([
            ['method_type' => 'cash', 'currency' => 'KES', 'count' => 3, 'amount' => ['amount_minor' => '247500', 'currency' => 'KES']],
            ['method_type' => 'mobile_money', 'currency' => 'KES', 'count' => 1, 'amount' => ['amount_minor' => '100000', 'currency' => 'KES']],
        ], $data['payments']);
        $this->assertSame([['amount_minor' => '10000', 'currency' => 'KES']], $data['change']);

        $this->assertSame('Soap', $data['top_items'][0]['item']['name']);
        $this->assertSame('6', $data['top_items'][0]['qty']);
        $this->assertSame(3, $data['top_items'][0]['sales_count']);
        $this->assertSame([['amount_minor' => '337500', 'currency' => 'KES']], $data['top_items'][0]['totals']);
    }

    public function test_reporting_currency_converts_at_the_companys_rate_or_says_it_is_missing(): void
    {
        $missing = $this->insights(['currency' => 'USD'])->assertOk()->json('data');
        $this->assertSame([$this->acme->id], $missing['missing_rates']);
        $this->assertFalse($missing['consolidated']['complete']);
        $this->assertSame(['amount_minor' => '0', 'currency' => 'USD'], $missing['consolidated']['total']);
        $this->assertNull($missing['companies'][0]['reporting']);

        $this->inTenant(fn () => ExchangeRate::create([
            'company_id' => $this->acme->id, 'base' => 'USD', 'quote' => 'KES', 'kind' => 'shop', 'mid' => '130', 'effective_at' => now()->subDay(), 'source' => 'test',
        ]));

        $data = $this->insights(['currency' => 'USD'])->assertOk()->json('data');
        // KES 3,375.00 / 130 = USD 25.96 (one rounding, half up).
        $this->assertSame(['amount_minor' => '2596', 'currency' => 'USD'], $data['consolidated']['total']);
        $this->assertSame(['amount_minor' => '865', 'currency' => 'USD'], $data['consolidated']['average_ticket']);
        $this->assertSame('130.00000000', $data['companies'][0]['reporting']['rate']['rate']);
        $this->assertSame([], $data['missing_rates']);
    }

    public function test_the_default_reporting_currency_is_the_first_configured_one(): void
    {
        $this->inTenant(fn () => CompanyCurrency::create(['company_id' => $this->acme->id, 'code' => 'USD', 'position' => 1]));

        $this->insights()->assertOk()
            ->assertJsonPath('data.reporting_currency', 'USD')
            ->assertJsonPath('data.missing_rates', [$this->acme->id]);
        // An explicit choice wins.
        $this->insights(['currency' => 'KES'])->assertOk()->assertJsonPath('data.reporting_currency', 'KES');
    }

    public function test_period_and_place_filters(): void
    {
        $this->insights(['from' => now()->addDays(2)->toDateString(), 'to' => now()->addDays(3)->toDateString()])->assertOk()
            ->assertJsonPath('data.sales_count', 0)->assertJsonPath('data.companies', []);
        $this->insights(['branch' => $this->branchB->id])->assertOk()->assertJsonPath('data.sales_count', 1);
        $this->insights(['location' => $this->locationA->id])->assertOk()->assertJsonPath('data.sales_count', 2);

        $this->insights(['to' => now()->subDays(3)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->insights(['from' => now()->subDays(400)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->insights(['currency' => 'usd'])->assertUnprocessable()->assertJsonValidationErrors('currency');
    }

    public function test_scoped_to_the_users_locations_and_tenant(): void
    {
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->insights([], $managerB)->assertOk()
            ->assertJsonPath('data.sales_count', 1)
            ->assertJsonPath('data.companies.0.branches.0.branch.id', $this->branchB->id)
            ->assertJsonCount(1, 'data.companies.0.branches');

        $hr = $this->userWith('hr_officer', Scope::tenant());
        $this->insights([], $hr)->assertForbidden();

        $other = $this->otherTenant();
        $this->insights(['company' => $other['company']->id])->assertUnprocessable()->assertJsonValidationErrors('company');

        // The other tenant's owner, with the module on, sees none of these sales.
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('pos'));
        $this->insights([], $other['user'])->assertOk()->assertJsonPath('data.sales_count', 0);
    }

    public function test_device_tokens_and_tenants_without_the_module_are_refused(): void
    {
        $this->getJson('/api/v1/pos/insights?from='.$this->today.'&to='.$this->today, $this->tillHeaders())->assertUnauthorized();

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('pos'));
        $this->insights()->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }
}
