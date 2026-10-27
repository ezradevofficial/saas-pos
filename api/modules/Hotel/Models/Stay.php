<?php

namespace Modules\Hotel\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * HOT-12, HOT-17: one room for a date range: its type, plan, party, the
 * quoted nightly rates (HOT-10) and where it stands. A stay holds a room
 * (counts against availability) while `reserved` or `checked_in`.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $reservation_id
 * @property string $guest_id
 * @property string $room_type_id
 * @property string $rate_plan_id
 * @property ?string $room_id
 * @property CarbonImmutable $arrival_on
 * @property CarbonImmutable $departure_on
 * @property int $adults
 * @property int $children
 * @property string $board
 * @property string $currency
 * @property list<array{date: string, rate_minor: int, extra_minor: int, amount_minor: int, season: ?string}> $nightly
 * @property int $total_minor
 * @property bool $rate_overridden
 * @property string $status
 * @property bool $bill_to_master
 */
class Stay extends Model
{
    use BelongsToTenant, HasUuids;

    public const RESERVED = 'reserved';

    public const CHECKED_IN = 'checked_in';

    public const CHECKED_OUT = 'checked_out';

    public const CANCELLED = 'cancelled';

    public const NO_SHOW = 'no_show';

    public const STATUSES = [self::RESERVED, self::CHECKED_IN, self::CHECKED_OUT, self::CANCELLED, self::NO_SHOW];

    /** Statuses that hold a room (HOT-13). */
    public const HOLDING = [self::RESERVED, self::CHECKED_IN];

    protected $table = 'hotel_stays';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'arrival_on' => 'immutable_date',
            'departure_on' => 'immutable_date',
            'adults' => 'integer',
            'children' => 'integer',
            'nightly' => 'array',
            'total_minor' => 'integer',
            'rate_overridden' => 'boolean',
            'bill_to_master' => 'boolean',
            'keys_issued' => 'integer',
            'checked_in_at' => 'immutable_datetime',
            'checked_out_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'no_show_at' => 'immutable_datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function folio(): HasOne
    {
        return $this->hasOne(Folio::class)->where('kind', Folio::GUEST);
    }

    public function nights(): int
    {
        return (int) $this->arrival_on->diffInDays($this->departure_on);
    }

    public function holdsRoom(): bool
    {
        return in_array($this->status, self::HOLDING, true);
    }
}
