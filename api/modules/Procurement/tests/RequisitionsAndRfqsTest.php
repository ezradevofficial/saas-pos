<?php

namespace Modules\Procurement\Tests;

use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\Rbac\Scope;
use Modules\Procurement\Documents\RequisitionType;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PRC-02 to PRC-05, PRC-20: requisitions through their flow, RFQs with the
// quotes typed in, the comparison in base currency and the award to draft
// POs; custom fields on requisitions.
class RequisitionsAndRfqsTest extends TestCase
{
    use BuildsProcurement, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurement();
    }

    private function approvedRequisition(): array
    {
        $storekeeper = $this->userWith('storekeeper', Scope::location($this->locationA->id));
        $requisition = $this->api('POST', 'procurement/requisitions', [
            'branch_id' => $this->branchA->id, 'location_id' => $this->locationA->id, 'purpose' => 'Restock the outlet',
            'lines' => [['item_id' => $this->soap->id, 'qty' => '24', 'estimated_unit_price_minor' => '9500'], ['description' => 'Shelf brackets', 'qty' => '4']],
        ], $this->manager)->assertCreated()->json('data');
        // 24 × 95.00 = 2,280.00 estimated.
        $this->assertSame(['amount_minor' => '228000', 'currency' => 'KES'], $requisition['estimated_total']);
        // A storekeeper at the outlet sees it (its location), a cashier does not.
        $this->api('GET', "procurement/requisitions/{$requisition['id']}", [], $storekeeper)->assertOk();

        $this->api('POST', "procurement/requisitions/{$requisition['id']}/submit", [], $this->manager)->assertOk()->assertJsonPath('data.status', 'pending');
        // APR-07: the manager requested it; another manager approves.
        $other = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->approveFlow(RequisitionType::KEY, $requisition['id'], $other);

        return $this->api('GET', "procurement/requisitions/{$requisition['id']}")->assertOk()->json('data');
    }

    public function test_a_requisition_is_approved_through_its_flow_and_takes_custom_fields(): void
    {
        $this->inTenant(fn () => CustomFieldDefinition::query()->create([
            'entity' => 'procurement_requisition', 'key' => 'urgency', 'label' => 'Urgency', 'type' => 'text',
        ]));
        $created = $this->api('POST', 'procurement/requisitions', [
            'branch_id' => $this->branchA->id, 'custom' => ['urgency' => 'High'], 'lines' => [['description' => 'Paper', 'qty' => '5']],
        ], $this->buyer)->assertCreated()->json('data');
        $this->assertSame('High', $created['custom']['urgency']);
        $this->assertNull($created['number']);

        $requisition = $this->approvedRequisition();
        $this->assertSame('approved', $requisition['status']);
        $this->assertMatchesRegularExpression('/^PR-/', $requisition['number']);
        $this->assertTrue($requisition['can']['order']);
        // Approved requisitions are no longer edited or cancelled.
        $this->api('PATCH', "procurement/requisitions/{$requisition['id']}", ['purpose' => 'Other'])->assertStatus(409);
        $this->api('POST', "procurement/requisitions/{$requisition['id']}/cancel", ['reason' => 'No'])->assertStatus(409);
    }

    public function test_an_rfq_compares_quotes_in_base_currency_and_awards_draft_pos(): void
    {
        $requisition = $this->approvedRequisition();
        $rfq = $this->api('POST', 'procurement/rfqs', [
            'branch_id' => $this->branchA->id, 'requisition_id' => $requisition['id'], 'title' => 'Soap and brackets',
            'deadline_at' => '2026-10-18T12:00:00Z', 'supplier_ids' => [$this->supplier->id, $this->otherSupplier->id],
        ], $this->buyer)->assertCreated()->json('data');
        // The requisition's lines are taken.
        $this->assertCount(2, $rfq['lines']);
        $this->api('POST', "procurement/rfqs/{$rfq['id']}/issue", [], $this->buyer)->assertOk()->assertJsonPath('data.status', 'issued');
        $this->assertSame('ordered', $this->api('GET', "procurement/requisitions/{$requisition['id']}")->json('data.status'));
        [$soap, $brackets] = $rfq['lines'];

        // Upesi in KES; Baraka in USD (1 USD = 130 KES): soap 0.70 USD = KES 91.00 is lower, brackets higher.
        $this->api('POST', 'procurement/quotes', ['rfq_id' => $rfq['id'], 'party_id' => $this->supplier->id, 'quote_date' => '2026-10-16', 'currency' => 'KES',
            'lines' => [['rfq_line_id' => $soap['id'], 'unit_price_minor' => '9300'], ['rfq_line_id' => $brackets['id'], 'unit_price_minor' => '25000', 'lead_time_days' => 3]]], $this->buyer)->assertCreated();
        $this->api('POST', 'procurement/quotes', ['rfq_id' => $rfq['id'], 'party_id' => $this->otherSupplier->id, 'quote_date' => '2026-10-16', 'currency' => 'USD',
            'lines' => [['rfq_line_id' => $soap['id'], 'unit_price_minor' => '70'], ['rfq_line_id' => $brackets['id'], 'unit_price_minor' => '2000']]], $this->buyer)->assertCreated();
        // Someone not invited cannot quote.
        $this->api('POST', 'procurement/quotes', ['rfq_id' => $rfq['id'], 'party_id' => $this->customer->id, 'quote_date' => '2026-10-16', 'currency' => 'KES',
            'lines' => [['rfq_line_id' => $soap['id'], 'unit_price_minor' => '1']]], $this->buyer)->assertUnprocessable()->assertJsonPath('code', 'supplier_not_invited');

        $grid = $this->api('GET', "procurement/rfqs/{$rfq['id']}/comparison")->assertOk()->json('data');
        $this->assertSame('KES', $grid['base_currency']);
        $lowest = fn (array $line) => collect($line['offers'])->firstWhere('lowest', true);
        $this->assertSame('9100', $lowest($grid['lines'][0])['base_unit_price']['amount_minor']);
        $this->assertSame($this->otherSupplier->id, $lowest($grid['lines'][0])['party_id']);
        $this->assertSame($this->supplier->id, $lowest($grid['lines'][1])['party_id']);

        // Award the lowest of each: one draft PO per supplier, in its quote's currency.
        $orders = $this->api('POST', "procurement/rfqs/{$rfq['id']}/award", [], $this->buyer)->assertCreated()->json('data');
        $this->assertCount(2, $orders);
        $usd = collect($orders)->firstWhere('currency', 'USD');
        $this->assertSame('draft', $usd['status']);
        $this->assertSame(['amount_minor' => '1680', 'currency' => 'USD'], $usd['subtotal']);
        $this->assertSame('closed', $this->api('GET', "procurement/rfqs/{$rfq['id']}")->json('data.status'));
        $this->assertSame(2, $this->inTenant(fn () => Order::query()->where('requisition_id', $requisition['id'])->count()));
    }

    public function test_an_rfq_needs_suppliers_and_a_future_deadline(): void
    {
        $rfq = $this->api('POST', 'procurement/rfqs', ['branch_id' => $this->branchA->id, 'title' => 'Paper', 'lines' => [['description' => 'A4 paper', 'qty' => '10']]], $this->buyer)->assertCreated()->json('data');
        $this->api('POST', "procurement/rfqs/{$rfq['id']}/issue", [], $this->buyer)->assertUnprocessable()->assertJsonPath('code', 'suppliers_required');
        $this->api('PATCH', "procurement/rfqs/{$rfq['id']}", ['supplier_ids' => [$this->supplier->id], 'deadline_at' => '2026-10-10T00:00:00Z'], $this->buyer)->assertOk();
        $this->api('POST', "procurement/rfqs/{$rfq['id']}/issue", [], $this->buyer)->assertUnprocessable()->assertJsonPath('code', 'deadline_in_past');
        // A customer is not a supplier.
        $this->api('PATCH', "procurement/rfqs/{$rfq['id']}", ['supplier_ids' => [$this->customer->id]], $this->buyer)->assertUnprocessable()->assertJsonPath('code', 'party_not_supplier');
    }
}
