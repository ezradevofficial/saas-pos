<?php

namespace Modules\POS\Webhooks;

use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Catalogue\WebhookOccurrence;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleSnapshot;
use Modules\POS\Events\SaleVoided;
use Modules\POS\Models\Refund;
use Modules\POS\Models\Sale;
use Modules\POS\PosServiceProvider;

/**
 * HOOK-01: the POS module's public webhook events (docs/webhooks.md).
 * Captured with ids, the place and the event's own time only (no queries,
 * nothing else queued); the fan-out job reads the documented fields from
 * POS's own tables (SaleSnapshot), so no value sits in the queue. Amounts
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
            capture: fn (SaleCompleted $e) => self::reference(['sale_id' => $e->saleId], $e->sale + ['company_id' => $e->companyId, 'location_id' => $e->locationId], $e->sale['sold_at'] ?? null),
            resolve: function (WebhookOccurrence $occurrence): ?WebhookOccurrence {
                $sale = Sale::query()->find($occurrence->data['sale_id'] ?? null);

                return $sale === null ? null : $occurrence->with(self::sale(SaleSnapshot::sale($sale)));
            },
            fields: ['sale_id', 'receipt_number', 'status', 'company_id', 'branch_id', 'location_id', 'device_id', 'customer_id', 'sold_at', 'offline', 'currency',
                'totals.subtotal_minor', 'totals.discount_minor', 'totals.tax_minor', 'totals.total_minor', 'totals.paid_minor',
                'lines[].line_no', 'lines[].item_id', 'lines[].uom_id', 'lines[].qty', 'lines[].unit_price_minor', 'lines[].discount_minor', 'lines[].tax_minor', 'lines[].total_minor'],
        ));

        $catalogue->register(new WebhookEventType(
            key: 'sale.voided.v1',
            module: PosServiceProvider::MODULE,
            event: SaleVoided::class,
            permission: 'pos.sale.view',
            capture: fn (SaleVoided $e) => self::reference(['sale_id' => $e->saleId, 'void_id' => $e->voidId], $e->sale, $e->voidedAt ?? ($e->sale['voided_at'] ?? null)),
            resolve: function (WebhookOccurrence $occurrence): ?WebhookOccurrence {
                $sale = Sale::query()->find($occurrence->data['sale_id'] ?? null);

                return $sale === null ? null : $occurrence->with([
                    'sale_id' => $sale->id,
                    'void_id' => $occurrence->data['void_id'] ?? null,
                    'receipt_number' => $sale->receipt_number,
                    'company_id' => $sale->company_id,
                    'branch_id' => $sale->branch_id,
                    'location_id' => $sale->location_id,
                    'voided_at' => $occurrence->occurredAt,
                    'currency' => $sale->currency,
                    'total_minor' => (string) $sale->total_minor,
                ]);
            },
            fields: ['sale_id', 'void_id', 'receipt_number', 'company_id', 'branch_id', 'location_id', 'voided_at', 'currency', 'total_minor'],
        ));

        $catalogue->register(new WebhookEventType(
            key: 'sale.refunded.v1',
            module: PosServiceProvider::MODULE,
            event: SaleRefunded::class,
            permission: 'pos.sale.view',
            capture: fn (SaleRefunded $e) => self::reference(['refund_id' => $e->refundId, 'sale_id' => $e->saleId], $e->refund, $e->refund['refunded_at'] ?? null),
            resolve: function (WebhookOccurrence $occurrence): ?WebhookOccurrence {
                $refund = Refund::query()->find($occurrence->data['refund_id'] ?? null);

                if ($refund === null) {
                    return null;
                }

                $snapshot = SaleSnapshot::refund($refund);

                return $occurrence->with([
                    'refund_id' => $snapshot['id'],
                    'sale_id' => $snapshot['sale_id'],
                    'receipt_number' => $snapshot['receipt_number'] ?? null,
                    'company_id' => $snapshot['company_id'] ?? null,
                    'branch_id' => $snapshot['branch_id'] ?? null,
                    'location_id' => $snapshot['location_id'] ?? null,
                    'customer_id' => $snapshot['customer_id'] ?? null,
                    'refunded_at' => $snapshot['refunded_at'] ?? null,
                    'currency' => $snapshot['currency'] ?? null,
                    'total_minor' => $snapshot['total_minor'] ?? null,
                    'tax_minor' => $snapshot['tax_minor'] ?? null,
                    'lines' => array_map(fn (array $line) => [
                        'sale_line_id' => $line['sale_line_id'] ?? null,
                        'item_id' => $line['item_id'] ?? null,
                        'qty' => $line['qty'] ?? null,
                        'total_minor' => $line['total_minor'] ?? null,
                    ], (array) ($snapshot['lines'] ?? [])),
                ]);
            },
            fields: ['refund_id', 'sale_id', 'receipt_number', 'company_id', 'branch_id', 'location_id', 'customer_id', 'refunded_at', 'currency', 'total_minor', 'tax_minor',
                'lines[].sale_line_id', 'lines[].item_id', 'lines[].qty', 'lines[].total_minor'],
        ));
    }

    /**
     * @param  array<string, mixed>  $sale  SaleSnapshot::sale()
     * @return array<string, mixed>
     */
    public static function sale(array $sale): array
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

    /**
     * The queued reference: ids, where it happened and when; never values.
     *
     * @param  array<string, string>  $ids
     * @param  array<string, mixed>  $place  a snapshot with company_id, branch_id, location_id
     */
    private static function reference(array $ids, array $place, ?string $at): WebhookOccurrence
    {
        return new WebhookOccurrence($ids, $place['company_id'] ?? null, $place['branch_id'] ?? null, $place['location_id'] ?? null, occurredAt: $at);
    }
}
