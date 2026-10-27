<?php

namespace Modules\Salon\Models;

use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-11, SAL-17: the deposit ledger of an appointment. `deposit` and `restored` add to the balance; `refund` and
 * `applied` take from it. A pending row is an M-Pesa push still waiting (its id is the payment intent's).
 *
 * @property string $appointment_id
 * @property string $kind
 * @property string $status
 * @property ?string $payment_method_id
 * @property ?string $method_type
 * @property string $currency
 * @property int $amount_minor
 * @property ?string $reference
 * @property ?string $payment_intent_id
 * @property ?string $sale_id
 * @property ?string $sale_payment_id
 */
class Payment extends Model
{
    use BelongsToTenant, HasUuids;

    public const DEPOSIT = 'deposit';

    public const REFUND = 'refund';

    public const APPLIED = 'applied';

    public const RESTORED = 'restored';

    public const PENDING = 'pending';

    public const SETTLED = 'settled';

    public const FAILED = 'failed';

    /** The payment intent reference type (core payment intents). */
    public const REFERENCE = 'salon.payment';

    protected $table = 'salon_payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    /** The scope of the appointment this payment is on. */
    public function recordScope(): Scope
    {
        return Appointment::query()->findOrFail($this->appointment_id)->scope();
    }
}
