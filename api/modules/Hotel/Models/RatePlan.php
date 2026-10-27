<?php

namespace Modules\Hotel\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HOT-06 to HOT-09: a rate plan of a room type: weekday and weekend rates,
 * board basis, extra person and child pricing, and optional cancellation
 * rules of its own. Rates are minor units of `currency`.
 *
 * @property string $id
 * @property string $company_id
 * @property string $room_type_id
 * @property string $code
 * @property string $name
 * @property string $board
 * @property string $currency
 * @property int $weekday_rate_minor
 * @property int $weekend_rate_minor
 * @property int $extra_adult_minor
 * @property int $child_rate_minor
 * @property int $free_children
 * @property bool $tax_inclusive
 */
class RatePlan extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const BOARDS = ['room_only', 'bed_breakfast', 'half_board'];

    protected string $auditModule = 'hotel';

    protected string $auditResource = 'rate_plan';

    protected $table = 'hotel_rate_plans';

    protected $guarded = ['id', 'tenant_id', 'archived_at'];

    protected function casts(): array
    {
        return [
            'weekday_rate_minor' => 'integer',
            'weekend_rate_minor' => 'integer',
            'extra_adult_minor' => 'integer',
            'child_rate_minor' => 'integer',
            'free_children' => 'integer',
            'tax_inclusive' => 'boolean',
            'active' => 'boolean',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'cancel_free_days' => 'integer',
            'cancel_charge_value' => 'integer',
            'noshow_charge_value' => 'integer',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(RateSeason::class, 'rate_plan_id')->orderBy('starts_on');
    }
}
