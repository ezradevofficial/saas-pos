<?php

namespace Modules\Hotel\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HOT-14, HOT-24: a deposit, payment or refund on a folio and where it
 * stands. It becomes a folio line when settled (immediately for cash, on the
 * provider's answer for an M-Pesa push). `converted_at` marks a deposit
 * applied at check-in, cancellation or no-show.
 *
 * @property string $id
 * @property string $folio_id
 * @property string $kind
 * @property string $status
 * @property string $payment_method_id
 * @property string $method_type
 * @property string $currency
 * @property int $amount_minor
 * @property ?string $reference
 * @property ?string $payment_intent_id
 * @property ?string $line_id
 * @property ?CarbonImmutable $converted_at
 */
class Payment extends Model
{
    use BelongsToTenant, HasUuids;

    public const DEPOSIT = 'deposit';

    public const PAYMENT = 'payment';

    public const REFUND = 'refund';

    public const PENDING = 'pending';

    public const SETTLED = 'settled';

    public const FAILED = 'failed';

    public const REFERENCE = 'hotel.payment';

    protected $table = 'hotel_payments';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'converted_at' => 'immutable_datetime'];
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }
}
