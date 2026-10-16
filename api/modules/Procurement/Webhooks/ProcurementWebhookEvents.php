<?php

namespace Modules\Procurement\Webhooks;

use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Catalogue\WebhookOccurrence;
use Modules\Procurement\Events\PurchaseOrderApproved;
use Modules\Procurement\Events\SupplierInvoiceApproved;
use Modules\Procurement\ProcurementServiceProvider;

/**
 * HOOK-01: Procurement's public webhook events (docs/webhooks.md), from
 * what its events carry. Amounts are minor-unit digit strings in the
 * document's currency unless named `base_`.
 */
final class ProcurementWebhookEvents
{
    public static function register(WebhookCatalogue $catalogue): void
    {
        $catalogue->register(new WebhookEventType(
            key: 'purchase_order.approved.v1',
            module: ProcurementServiceProvider::MODULE,
            event: PurchaseOrderApproved::class,
            permission: 'procurement.po.view',
            capture: fn (PurchaseOrderApproved $e) => new WebhookOccurrence([
                'purchase_order_id' => $e->orderId,
                'number' => $e->number,
                'company_id' => $e->companyId,
                'branch_id' => $e->branchId,
                'party_id' => $e->partyId,
                'currency' => $e->currency,
                'total_minor' => $e->totalMinor,
                'base_currency' => $e->baseCurrency,
                'base_total_minor' => $e->baseTotalMinor,
            ], $e->companyId, $e->branchId),
            fields: ['purchase_order_id', 'number', 'company_id', 'branch_id', 'party_id', 'currency', 'total_minor', 'base_currency', 'base_total_minor'],
        ));

        $catalogue->register(new WebhookEventType(
            key: 'supplier_invoice.approved.v1',
            module: ProcurementServiceProvider::MODULE,
            event: SupplierInvoiceApproved::class,
            permission: 'procurement.invoice.view',
            capture: function (SupplierInvoiceApproved $e) {
                $document = $e->document;
                $branchId = $document['branch_id'] ?? ($e->dimensions['branch_id'] ?? null);

                return new WebhookOccurrence([
                    'supplier_invoice_id' => $e->invoiceId,
                    'number' => $document['number'] ?? null,
                    'supplier_reference' => $document['supplier_reference'] ?? $e->reference,
                    'company_id' => $e->companyId,
                    'branch_id' => $branchId,
                    'party_id' => $document['party_id'] ?? ($e->dimensions['party_id'] ?? null),
                    'invoice_date' => $document['invoice_date'] ?? $e->date,
                    'due_date' => $document['due_date'] ?? null,
                    'currency' => $e->currency,
                    'net_minor' => $document['totals']['net'] ?? null,
                    'tax_minor' => $document['totals']['tax'] ?? null,
                    'total_minor' => $document['totals']['total'] ?? null,
                ], $e->companyId, is_string($branchId) ? $branchId : null);
            },
            fields: ['supplier_invoice_id', 'number', 'supplier_reference', 'company_id', 'branch_id', 'party_id', 'invoice_date', 'due_date', 'currency', 'net_minor', 'tax_minor', 'total_minor'],
        ));
    }
}
