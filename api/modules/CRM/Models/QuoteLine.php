<?php

namespace Modules\CRM\Models;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Taxes\TaxCode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRM-18: one line of a quote, its amounts in minor units of the quote's
 * currency (gross, discount, net, tax, total).
 *
 * @property string $quote_id
 * @property int $line_no
 * @property ?string $item_id
 * @property string $description
 * @property ?string $uom_id
 * @property string $qty
 * @property ?int $list_price_minor
 * @property int $unit_price_minor
 * @property string $discount_percent
 * @property int $gross_minor
 * @property int $discount_minor
 * @property int $net_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property ?string $tax_code_id
 * @property ?string $tax_rate
 */
class QuoteLine extends CrmModel
{
    protected $table = 'crm_quote_lines';

    protected string $auditResource = 'quote_line';

    protected function casts(): array
    {
        return [
            'list_price_minor' => 'integer', 'unit_price_minor' => 'integer', 'gross_minor' => 'integer', 'discount_minor' => 'integer',
            'net_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
