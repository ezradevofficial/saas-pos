<?php

namespace Modules\Procurement\Tests\Concerns;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Currency\TenantCurrencies;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\Procurement\Documents\OrderType;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Tenders\Bidders;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\BuildsOrganisation;

/**
 * A tenant with Procurement active (BuildsOrganisation's Acme, KE, base
 * KES, USD active at 1 USD = 130 KES), units EA and BOX (12) and KG, a
 * stock item (soap, boxes of 12), a service (delivery), two suppliers, a
 * customer, a synthetic test VAT code at 16 % (never a real rate), a
 * Branch Manager at branch A (the default approver) and a Procurement
 * Officer at branch A. Time is fixed at 2026-10-15 09:00 UTC.
 */
trait BuildsProcurement
{
    use BuildsExchangeRates, BuildsOrganisation;

    protected Uom $each;

    protected Uom $box;

    protected Item $soap;

    protected Item $delivery;

    protected Party $supplier;

    protected Party $otherSupplier;

    protected Party $customer;

    protected TaxCode $vat;

    protected User $manager;

    protected User $buyer;

    protected function setUpProcurement(bool $inventory = false): void
    {
        Notification::fake();
        Mail::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15T09:00:00Z'));
        $this->setUpOrganisation();

        $this->inTenant(function () use ($inventory) {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            app(ModuleRegistry::class)->activate('procurement');

            if ($inventory) {
                app(ModuleRegistry::class)->activate('inventory');
            }

            $this->each = Uom::create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count']);
            $this->box = Uom::create(['code' => 'BOX', 'name' => 'Box', 'kind' => 'count']);
            Uom::create(['code' => 'KG', 'name' => 'Kilogram', 'kind' => 'weight']);
            $this->soap = Item::create(['code' => 'SOAP', 'name' => 'Soap', 'type' => 'stock', 'base_uom_id' => $this->each->id]);
            ItemUom::create(['item_id' => $this->soap->id, 'uom_id' => $this->box->id, 'factor' => '12']);
            $this->delivery = Item::create(['code' => 'DELIVERY', 'name' => 'Delivery', 'type' => 'service', 'base_uom_id' => $this->each->id]);
            $this->supplier = Party::create(['kind' => 'organisation', 'name' => 'Upesi Supplies', 'roles' => ['supplier'], 'company_id' => $this->acme->id,
                'emails' => [['address' => 'sales@upesi.example.com', 'label' => null]]]);
            $this->otherSupplier = Party::create(['kind' => 'organisation', 'name' => 'Baraka Wholesale', 'roles' => ['supplier'], 'company_id' => $this->acme->id,
                'emails' => [['address' => 'bids@baraka.example.com', 'label' => null]]]);
            $this->customer = Party::create(['kind' => 'organisation', 'name' => 'Walk-in Ltd', 'roles' => ['customer'], 'company_id' => $this->acme->id]);
            $this->vat = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'VAT_T', 'name' => 'VAT test', 'kind' => 'vat']);
            TaxRate::create(['tax_code_id' => $this->vat->id, 'rate' => '16', 'effective_from' => '2026-01-01']);
        });

        $this->rate('USD', 'KES', '130', at: '2026-10-01T00:00:00Z');
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->buyer = $this->userWith('procurement_officer', Scope::branch($this->branchA->id));
    }

    protected function api(string $method, string $uri, array $body = [], ?User $as = null): TestResponse
    {
        return $this->json($method, '/api/v1/'.ltrim($uri, '/'), $body, $this->headersFor($as ?? $this->owner));
    }

    /** A draft PO through the API (soap and delivery, KES unless said), returned as JSON. */
    protected function draftOrder(array $extra = [], ?array $lines = null): array
    {
        return $this->api('POST', 'procurement/orders', [
            'branch_id' => $this->branchA->id,
            'party_id' => $this->supplier->id,
            'expected_on' => '2026-10-20',
            'lines' => $lines ?? [
                ['item_id' => $this->soap->id, 'qty' => '10', 'unit_price_minor' => '10000', 'tax_code_id' => $this->vat->id],
                ['item_id' => $this->delivery->id, 'qty' => '1', 'unit_price_minor' => '50000'],
            ],
            ...$extra,
        ], $this->buyer)->assertCreated()->json('data');
    }

    /** A PO drafted, submitted and approved by the manager through its flow. */
    protected function approvedOrder(array $extra = [], ?array $lines = null): array
    {
        $order = $this->draftOrder($extra, $lines);
        $this->api('POST', "procurement/orders/{$order['id']}/submit", [], $this->buyer)->assertOk();
        $this->approveFlow(OrderType::KEY, $order['id']);

        return $this->api('GET', "procurement/orders/{$order['id']}")->assertOk()->json('data');
    }

    /** Approve the document's running flow as the branch manager (APR-07: never the requester). */
    protected function approveFlow(string $type, string $documentId, ?User $as = null): void
    {
        $approval = $this->inTenant(function () use ($type, $documentId) {
            $workflow = app(WorkflowEngine::class)->current($type, $documentId);
            $this->assertSame(DocumentWorkflow::RUNNING, $workflow?->status, 'the flow is running');

            return ApprovalRequest::query()->where('document_id', $documentId)->sole()->id;
        });

        $this->api('POST', "approvals/{$approval}/approve", [], $as ?? $this->manager)->assertOk();
    }

    /** A posted GRN for the PO (every line received in full unless $lines). */
    protected function postedReceipt(array $order, ?array $lines = null, array $extra = []): array
    {
        $receipt = $this->api('POST', 'procurement/receipts', [
            'order_id' => $order['id'],
            'lines' => $lines ?? array_map(fn (array $line) => ['order_line_id' => $line['id'], 'received_qty' => $line['qty']], $order['lines']),
            ...$extra,
        ])->assertCreated()->json('data');

        return $this->api('POST', "procurement/receipts/{$receipt['id']}/post")->assertOk()->json('data');
    }

    /** A fresh portal link for a bidder (the email carries the same one). */
    protected function linkFor(string $bidderId): string
    {
        return $this->inTenant(fn () => app(Bidders::class)->issueLinkForTesting(Bidder::query()->findOrFail($bidderId)));
    }

    /** A portal session's bearer headers for a link token. */
    protected function portal(string $link): array
    {
        $token = $this->postJson('/api/v1/supplier-portal/sessions', ['token' => $link])->assertCreated()->json('data.session_token');

        return ['Authorization' => 'Bearer '.$token];
    }
}
