<?php

namespace Modules\POS\Webhooks;

use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Catalogue\WebhookOccurrence;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
use Modules\POS\PosServiceProvider;

/**
 * HOOK-01: the POS module's public webhook events (docs/webhooks.md),
 * captured from the sale snapshots its events carry (no queries). Amounts
 * are minor-unit digit strings with the currency; quantities decimal
 * strings; times ISO 8601 UTC. Received with `pos.sale.view` at the sale's
 * location.
 */
final class PosWebhookEvents
{
    public static function register(WebhookCatalogue $catalogue): void
    {
        $catalogue->register(new WebhookEventType(
            key: 'sale.completed.v1',
            module: PosServiceProvider::MODULE,
            event: SaleCompleted::class,
            permission: 'pos.sale.view',
            capture: fn (SaleCompleted $e) => self::occurrence(self::sale($e->sale + ['id' => $e->saleId, 'company_id' => $e->companyId, 'location_id' => $e->locationId])),
            fields: ['sale_id', 'receipt_number', 'status', 'company_id', 'branch_id', 'location_id', 'device_id', 'customer_id', 'sold_at', 'offline', 'currency',
                'totals.subtotal_minor', 'totals.discount_minor', 'totals.tax_minor', 'totals.total_minor', 'totals.paid_minor',
                'lines[].line_no', 'lines[].item_id', 'lines[].uom_id', 'lines[].qty', 'lines[].unit_price_minor', 'lines[].discount_minor', 'lines[].tax_minor', 'lines[].total_minor'],
        ));

        $catalogue->register(new WebhookEventType(
            key: 'sale.voided.v1',
            module: PosServiceProvider::MODULE,
            event: SaleVoided::class,
            permission: 'pos.sale.view',
            capture: fn (SaleVoided $e) => self::occurrence([
                'sale_id' => $e->saleId,
                'void_id' => $e->voidId,
                'receipt_number' => $e->sale['receipt_number'] ?? null,
                'company_id' => $e->sale['company_id'] ?? null,
                'branch_id' => $e->sale['branch_id'] ?? null,
                'location_id' => $e->sale['location_id'] ?? null,
                'voided_at' => $e->voidedAt ?? ($e->sale['voided_at'] ?? null),
                'currency' => $e->sale['currency'] ?? null,
                'total_minor' => $e->sale['totals']['total_minor'] ?? null,
            ]),
            fields: ['sale_id', 'void_id', 'receipt_number', 'company_id', 'branch_id', 'location_id', 'voided_at', 'currency', 'total_minor'],
        ));

        $catalogue->register(new WebhookEventType(
            key: 'sale.refunded.v1',
            module: PosServiceProvider::MODULE,
            event: SaleRefunded::class,
            permission: 'pos.sale.view',
            capture: fn (SaleRefunded $e) => self::occurrence([
                'refund_id' => $e->refundId,
                'sale_id' => $e->saleId,
                'receipt_number' => $e->refund['receipt_number'] ?? null,
                'company_id' => $e->refund['company_id'] ?? null,
                'branch_id' => $e->refund['branch_id'] ?? null,
                'location_id' => $e->refund['location_id'] ?? null,
                'customer_id' => $e->refund['customer_id'] ?? null,
                'refunded_at' => $e->refund['refunded_at'] ?? null,
                'currency' => $e->refund['currency'] ?? null,
                'total_minor' => $e->refund['total_minor'] ?? null,
                'tax_minor' => $e->refund['tax_minor'] ?? null,
                'lines' => array_map(fn (array $line) => [
                    'sale_line_id' => $line['sale_line_id'] ?? null,
                    'item_id' => $line['item_id'] ?? null,
                    'qty' => $line['qty'] ?? null,
                    'total_minor' => $line['total_minor'] ?? null,
                ], (array) ($e->refund['lines'] ?? [])),
            ]),
            fields: ['refund_id', 'sale_id', 'receipt_number', 'company_id', 'branch_id', 'location_id', 'customer_id', 'refunded_at', 'currency', 'total_minor', 'tax_minor',
                'lines[].sale_line_id', 'lines[].item_id', 'lines[].qty', 'lines[].total_minor'],
        ));
    }

    /**
     * @param  array<string, mixed>  $sale  SaleSnapshot::sale()
     * @return array<string, mixed>
     */
    private static function sale(array $sale): array
    {
        $totals = (array) ($sale['totals'] ?? []);

        return [
            'sale_id' => $sale['id'],
            'receipt_number' => $sale['receipt_number'] ?? null,
            'status' => $sale['status'] ?? null,
            'company_id' => $sale['company_id'] ?? null,
            'branch_id' => $sale['branch_id'] ?? null,
            'location_id' => $sale['location_id'] ?? null,
            'device_id' => $sale['device_id'] ?? null,
            'customer_id' => $sale['customer_id'] ?? null,
            'sold_at' => $sale['sold_at'] ?? null,
            'offline' => (bool) ($sale['offline'] ?? false),
            'currency' => $sale['currency'] ?? null,
            'totals' => [
                'subtotal_minor' => $totals['subtotal_minor'] ?? null,
                'discount_minor' => $totals['discount_minor'] ?? null,
                'tax_minor' => $totals['tax_minor'] ?? null,
                'total_minor' => $totals['total_minor'] ?? null,
                'paid_minor' => $totals['paid_minor'] ?? null,
            ],
            'lines' => array_map(fn (array $line) => [
                'line_no' => $line['line_no'] ?? null,
                'item_id' => $line['item_id'] ?? null,
                'uom_id' => $line['uom_id'] ?? null,
                'qty' => $line['qty'] ?? null,
                'unit_price_minor' => $line['unit_price_minor'] ?? null,
                'discount_minor' => $line['discount_minor'] ?? null,
                'tax_minor' => $line['tax_minor'] ?? null,
                'total_minor' => $line['total_minor'] ?? null,
            ], (array) ($sale['lines'] ?? [])),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private static function occurrence(array $data): WebhookOccurrence
    {
        return new WebhookOccurrence($data, $data['company_id'] ?? null, $data['branch_id'] ?? null, $data['location_id'] ?? null);
    }
}
