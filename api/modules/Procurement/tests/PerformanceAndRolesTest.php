<?php

namespace Modules\Procurement\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Modules\Procurement\ProcurementServiceProvider;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PRC-01, PRC-18, RBAC-03, RBAC-08: company tolerances, supplier
// performance, and what the system roles may do in Procurement.
class PerformanceAndRolesTest extends TestCase
{
    use BuildsProcurement, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurement();
    }

    public function test_supplier_performance_counts_on_time_quality_and_price_variance(): void
    {
        $order = $this->approvedOrder(['expected_on' => '2026-10-15'], [['item_id' => $this->soap->id, 'qty' => '10', 'unit_price_minor' => '10000']]);
        $line = $order['lines'][0]['id'];
        // On time: 8 received, 2 of them rejected.
        $this->postedReceipt($order, [['order_line_id' => $line, 'received_qty' => '8', 'rejected_qty' => '2']]);
        // Late: 4 more.
        $this->clockTo('2026-10-17T09:00:00Z');
        $this->postedReceipt($order, [['order_line_id' => $line, 'received_qty' => '4']]);
        $invoice = $this->api('POST', 'procurement/invoices', ['order_id' => $order['id'], 'supplier_reference' => 'P-1', 'invoice_date' => '2026-10-17',
            'lines' => [['order_line_id' => $line, 'qty' => '10', 'unit_price_minor' => '10200']]], $this->buyer)->assertCreated()->json('data');
        $this->api('POST', "procurement/invoices/{$invoice['id']}/approve", ['override_reason' => 'Agreed'])->assertOk();

        $rows = $this->api('GET', 'procurement/supplier-performance?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('Upesi Supplies', $row['party']['name']);
        $this->assertSame(1, $row['orders']);
        $this->assertSame(2, $row['receipts']);
        $this->assertSame(5000, $row['on_time_bp']);
        // 10 accepted of 12 received.
        $this->assertSame(8333, $row['quality_bp']);
        // (102.00 − 100.00) × 10 = KES 20.00, 2 % of the ordered 1,000.00.
        $this->assertSame(['amount_minor' => '2000', 'currency' => 'KES'], $row['price_variance']);
        $this->assertSame(200, $row['price_variance_bp']);

        $csv = $this->get('/api/v1/procurement/supplier-performance?from=2026-10-01&to=2026-10-31&format=csv', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('Upesi Supplies', $csv);
        $this->assertStringContainsString('KES 20.00', $csv);
        // Someone without the permission is refused; a buyer of branch B sees nothing of branch A.
        $this->api('GET', 'procurement/supplier-performance', [], $this->userWith('cashier', Scope::branch($this->branchA->id)))->assertForbidden();
        $this->assertSame([], $this->api('GET', 'procurement/supplier-performance?from=2026-10-01&to=2026-10-31', [], $this->userWith('procurement_officer', Scope::branch($this->branchB->id)))->json('data'));
    }

    public function test_company_tolerances_are_read_and_set_with_their_permissions(): void
    {
        $uri = "procurement/companies/{$this->acme->id}/settings";
        $this->api('GET', $uri, [], $this->buyer)->assertOk()->assertJsonPath('data.price_tolerance_percent', '0');
        $this->api('PUT', $uri, ['price_tolerance_percent' => '2.5'], $this->buyer)->assertForbidden();
        $this->api('PUT', $uri, ['price_tolerance_percent' => '120'])->assertUnprocessable();
        $this->api('PUT', $uri, ['price_tolerance_percent' => '2.5', 'over_receipt_percent' => '5'])->assertOk()
            ->assertJsonPath('data.price_tolerance_percent', '2.5')->assertJsonPath('data.over_receipt_percent', '5');
    }

    public function test_roles_by_job_once_the_module_is_active(): void
    {
        $all = collect(ProcurementServiceProvider::PERMISSIONS)->flatMap(fn (array $actions, string $resource) => array_map(fn ($a) => "procurement.{$resource}.{$a}", $actions))->sort()->values()->all();
        $views = array_values(array_filter($all, fn ($p) => str_ends_with($p, '.view')));
        $held = function (string $template) use ($all) {
            $user = $this->userWith($template, Scope::branch($this->branchA->id));

            return $this->inTenant(fn () => collect($all)->filter(fn ($p) => app(ScopeResolver::class)->can($user, $p, Scope::branch($this->branchA->id)))->values()->all());
        };

        $this->assertSame($all, $held('owner'));
        $this->assertSame(collect([...$views, 'procurement.settings.edit'])->sort()->values()->all(), $held('admin'));
        $this->assertSame(array_values(array_diff($all, [
            'procurement.requisition.approve', 'procurement.po.approve', 'procurement.invoice.approve', 'procurement.invoice.override', 'procurement.tender.award', 'procurement.settings.edit',
        ])), $held('procurement_officer'));
        $this->assertSame(collect([...$views, 'procurement.requisition.create', 'procurement.requisition.approve', 'procurement.requisition.cancel',
            'procurement.po.approve', 'procurement.tender.open', 'procurement.tender.evaluate'])->unique()->sort()->values()->all(), $held('branch_manager'));
        $this->assertSame(['procurement.po.view', 'procurement.receipt.cancel', 'procurement.receipt.create', 'procurement.receipt.view',
            'procurement.requisition.create', 'procurement.requisition.view'], $held('storekeeper'));
        $this->assertSame(collect([...$views, 'procurement.invoice.create', 'procurement.invoice.approve', 'procurement.invoice.override', 'procurement.invoice.cancel',
            'procurement.landed_cost.create', 'procurement.landed_cost.cancel'])->unique()->sort()->values()->all(), $held('accountant'));
        $this->assertSame(['procurement.invoice.approve', 'procurement.invoice.view', 'procurement.po.approve', 'procurement.po.view',
            'procurement.requisition.approve', 'procurement.requisition.view'], $held('approver'));
        $this->assertSame($views, $held('read_only_auditor'));
        $this->assertSame([], $held('cashier'));

        // RBAC-08: with the module off, the same roles grant nothing of it.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('procurement'));
        $this->assertSame([], $held('procurement_officer'));
    }
}
