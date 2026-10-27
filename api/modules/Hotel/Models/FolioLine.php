<?php

namespace Modules\Hotel\Models;

use App\Core\Currency\FxSnapshot;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HOT-20, HOT-21: one line of a folio's append-only ledger (a trigger
 * refuses update and delete). `amount_minor` is signed and gross of tax:
 * charges positive, payments and deposits negative, refunds positive; a
 * mistake is a reversing `void` line naming the original in `reverses_id`.
 * `source` and `source_id` are unique per folio, so nothing posts twice.
 *
 * @property string $id
 * @property string $folio_id
 * @property int $line_no
 * @property string $kind
 * @property string $description
 * @property ?string $item_id
 * @property string $qty
 * @property ?int $unit_price_minor
 * @property int $amount_minor
 * @property int $net_minor
 * @property int $tax_minor
 * @property list<array{tax_code_id: string, code: string, rate: ?string, amount_minor: int}> $taxes
 * @property string $currency
 * @property CarbonImmutable $business_date
 * @property string $source
 * @property ?string $source_id
 * @property ?string $reverses_id
 * @property ?string $pair_id
 * @property ?string $payment_method_id
 * @property ?string $payment_id
 * @property list<string> $flags
 */
class FolioLine extends Model
{
    use BelongsToTenant, HasUuids;

    public const ROOM = 'room';

    public const EXTRA = 'extra';

    public const FEE = 'fee';

    public const POS_CHARGE = 'pos_charge';

    public const DEPOSIT = 'deposit';

    public const PAYMENT = 'payment';

    public const REFUND = 'refund';

    public const TRANSFER_IN = 'transfer_in';

    public const TRANSFER_OUT = 'transfer_out';

    public const VOID = 'void';

    /** Kinds that are the hotel's own charges (they make up the settlement invoice, HOT-27). */
    public const HOTEL_CHARGES = [self::ROOM, self::EXTRA, self::FEE];

    public const UPDATED_AT = null;

    protected $table = 'hotel_folio_lines';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'unit_price_minor' => 'integer',
            'amount_minor' => 'integer',
            'net_minor' => 'integer',
            'tax_minor' => 'integer',
            'taxes' => 'array',
            'flags' => 'array',
            'fx' => FxSnapshot::class.':fx',
            'base_amount_minor' => 'integer',
            'business_date' => 'immutable_date',
        ];
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }
}
