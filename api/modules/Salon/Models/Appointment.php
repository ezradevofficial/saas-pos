<?php

namespace Modules\Salon\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SAL-07: an appointment; its lines hold the staff and resource times. Guest contact details (people who booked with no
 * account) and the formula notes are personal data (NFR-08): registered with the privacy module, never audited in clear.
 *
 * @property string $company_id
 * @property string $branch_id
 * @property ?string $location_id
 * @property ?string $device_id
 * @property string $number
 * @property ?string $client_id
 * @property ?string $guest_name
 * @property ?string $guest_phone
 * @property ?string $guest_email
 * @property string $locale
 * @property string $source
 * @property string $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int $total_minor
 * @property string $currency
 * @property int $deposit_required_minor
 * @property ?CarbonImmutable $deposit_hold_until
 * @property int $fee_minor
 * @property string $fee_status
 * @property ?string $fee_reason
 * @property ?string $notes
 * @property ?string $service_notes
 * @property ?string $sale_id
 */
class Appointment extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const BOOKED = 'booked';

    public const CHECKED_IN = 'checked_in';

    public const IN_SERVICE = 'in_service';

    public const COMPLETED = 'completed';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const NO_SHOW = 'no_show';

    /** Statuses whose lines hold a staff member's and a resource's time. */
    public const HOLDING = [self::BOOKED, self::CHECKED_IN, self::IN_SERVICE, self::COMPLETED, self::PAID];

    /** Statuses a booking can still be moved or cancelled in. */
    public const OPEN = [self::BOOKED, self::CHECKED_IN];

    protected $table = 'salon_appointments';

    protected string $auditModule = 'salon';

    protected string $auditResource = 'appointment';

    protected array $auditHidden = ['guest_name', 'guest_phone', 'guest_email', 'notes', 'service_notes', 'cancel_reason', 'fee_waive_reason'];

    protected $guarded = [];

    protected $hidden = ['service_notes'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'deposit_hold_until' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime',
            'anonymised_at' => 'immutable_datetime',
            'total_minor' => 'integer', 'deposit_required_minor' => 'integer', 'fee_minor' => 'integer',
            'service_notes' => 'encrypted',
        ];
    }

    public function scope(): Scope
    {
        return $this->location_id !== null ? Scope::location($this->location_id) : Scope::branch($this->branch_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(Line::class)->orderBy('position')->orderBy('id');
    }
}
