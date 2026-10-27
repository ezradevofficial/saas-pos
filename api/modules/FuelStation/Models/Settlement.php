<?php

namespace Modules\FuelStation\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** FUEL-13 to FUEL-15: an attendant's settlement of a shift (expected, collected, variance) and its approval state. Audited by Settlements. */
class Settlement extends Model
{
    use BelongsToTenant, HasUuids;

    public const OPEN = 'open';

    public const PENDING = 'pending_approval';

    public const AUTO = 'auto_accepted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Its approval was cancelled: it can be submitted again (FUEL-14). */
    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::OPEN, self::PENDING, self::AUTO, self::APPROVED, self::REJECTED, self::CANCELLED];

    protected $table = 'fuel_settlements';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'expected_minor' => 'integer',
            'collected_minor' => 'integer',
            'variance_minor' => 'integer',
            'flags' => 'array',
            'decided_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(SettlementTender::class, 'settlement_id');
    }
}
