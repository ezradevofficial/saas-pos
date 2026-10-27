<?php

namespace Modules\Hotel\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HOT-04, HOT-05: a room of a property (branch) with its status. Creates,
 * updates and status changes are audited (`hotel.room.*`).
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $room_type_id
 * @property string $number
 * @property ?string $floor
 * @property list<string> $features
 * @property string $status
 */
class Room extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const VACANT_CLEAN = 'vacant_clean';

    public const VACANT_DIRTY = 'vacant_dirty';

    public const OCCUPIED = 'occupied';

    public const OUT_OF_ORDER = 'out_of_order';

    public const STATUSES = [self::VACANT_CLEAN, self::VACANT_DIRTY, self::OCCUPIED, self::OUT_OF_ORDER];

    protected string $auditModule = 'hotel';

    protected string $auditResource = 'room';

    protected $table = 'hotel_rooms';

    protected $guarded = ['id', 'tenant_id', 'archived_at'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'ooo_from' => 'immutable_date',
            'ooo_until' => 'immutable_date',
            'status_changed_at' => 'immutable_datetime',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
