<?php

namespace Modules\POS\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// POS-12, RBAC-04, EXP-01: sales and shifts in the back office, scoped by
// the user's locations, with filters, search, sort, detail and export.
class BackOfficeTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private string $shiftA;

    private array $saleA;

    private array $saleB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPos();
        $this->ranges()->assertOk();
        $this->shiftA = $this->openShift();
        $this->saleA = $this->saleBody($this->shiftA, 1, ['customer_id' => $this->customer()->id]);
        $this->upload([$this->saleA])->assertOk();

        [, $tokenB] = $this->pairedTill($this->locationB, 'Till B');
        $this->ranges(token: $tokenB)->assertOk();
        $shiftB = $this->openShift($tokenB);
        $this->saleB = $this->saleBody($shiftB, 501, ['receipt_number' => 'R-L02-000501']);
        $this->upload([$this->saleB], $tokenB)->assertOk();
    }

    public function test_lists_show_only_the_users_locations(): void
    {
        $this->assertCount(2, $this->getJson('/api/v1/pos/sales', $this->headersFor())->assertOk()->json('data'));

        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->getJson('/api/v1/pos/sales', $this->headersFor($managerB))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.receipt_number', 'R-L02-000501');
        $this->getJson("/api/v1/pos/sales/{$this->saleA['id']}", $this->headersFor($managerB))->assertNotFound();
        $this->getJson("/api/v1/pos/shifts/{$this->shiftA}", $this->headersFor($managerB))->assertNotFound();

        $cashierA = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->getJson('/api/v1/pos/shifts', $this->headersFor($cashierA))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->shiftA);

        // The HR officer holds no POS permission.
        $hr = $this->userWith('hr_officer', Scope::tenant());
        $this->getJson('/api/v1/pos/sales', $this->headersFor($hr))->assertForbidden();
    }

    public function test_filters_search_sort_and_detail(): void
    {
        $owner = $this->headersFor();

        $this->getJson("/api/v1/pos/sales?location={$this->locationA->id}", $owner)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->saleA['id']);
        $this->getJson("/api/v1/pos/sales?branch={$this->branchB->id}", $owner)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->saleB['id']);
        $this->getJson('/api/v1/pos/sales?search=L02', $owner)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/pos/sales?status=voided', $owner)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/pos/sales?from='.now()->addDay()->toDateString(), $owner)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/pos/sales?sort=receipt_number', $owner)->assertOk()->assertJsonPath('data.0.receipt_number', 'R-L01-000001');
        $this->getJson('/api/v1/pos/sales?sort=nope', $owner)->assertUnprocessable();

        $this->getJson("/api/v1/pos/sales/{$this->saleA['id']}", $owner)->assertOk()
            ->assertJsonPath('data.customer.name', 'Amina')
            ->assertJsonPath('data.total', ['amount_minor' => '112500', 'currency' => 'KES'])
            ->assertJsonPath('data.lines.0.item.name', 'Soap')
            ->assertJsonPath('data.lines.0.tax', ['amount_minor' => '12500', 'currency' => 'KES'])
            ->assertJsonPath('data.payments.0.method_type', 'cash')
            ->assertJsonPath('data.void', null);

        $this->getJson("/api/v1/pos/shifts/{$this->shiftA}", $owner)->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.sales_count', 1)
            ->assertJsonPath('data.balances.0.opening', ['amount_minor' => '500000', 'currency' => 'KES']);
    }

    public function test_exports_list_the_visible_sales_and_are_audited(): void
    {
        $csv = $this->get('/api/v1/pos/sales?format=csv', $this->headersFor())->assertOk()->streamedContent();

        $this->assertStringContainsString('R-L01-000001', $csv);
        $this->assertStringContainsString('KES 1,125.00', $csv);
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'pos.sale.export')->count()));

        $shifts = $this->get('/api/v1/pos/shifts?format=csv', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('KES 5,000.00', $shifts);
    }

    public function test_device_tokens_and_tenants_without_the_module_are_refused(): void
    {
        $this->getJson('/api/v1/pos/sales', $this->tillHeaders())->assertUnauthorized();

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('pos'));
        $this->getJson('/api/v1/pos/sales', $this->headersFor())->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }
}
