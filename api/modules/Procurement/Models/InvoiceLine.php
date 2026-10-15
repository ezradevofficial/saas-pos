<?php

namespace Modules\Procurement\Models;

use App\Core\MasterData\Taxes\TaxCode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRC-15: an invoiced PO line and its match result.
 *
 * @property string $invoice_id
 * @property string $order_line_id
 * @property string $qty
 * @property int $unit_price_minor
 * @property ?string $tax_code_id
 * @property ?string $tax_rate
 * @property int $net_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property ?string $match_status
 * @property int $price_variance_minor
 * @property string $qty_variance
 * @property ?int $base_net_minor
 * @property ?int $base_grni_minor
 * @property ?int $base_stock_net_minor
 */
class InvoiceLine extends ProcurementModel
{
    public const MATCHED = 'matched';

    public const PRICE_VARIANCE = 'price_variance';

    public const QTY_EXCEEDS_RECEIVED = 'qty_exceeds_received';

    public const NOT_RECEIVED = 'not_received';

    protected $table = 'procurement_invoice_lines';

    protected function casts(): array
    {
        return [
            'line_no' => 'integer', 'unit_price_minor' => 'integer', 'net_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer',
            'price_variance_minor' => 'integer', 'base_net_minor' => 'integer', 'base_grni_minor' => 'integer', 'base_stock_net_minor' => 'integer',
        ];
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
