<?php

namespace Modules\Hotel\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HOT-28: a housekeeping task for a room on a business date.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $room_id
 * @property string $kind
 * @property string $status
 * @property CarbonImmutable $business_date
 * @property ?string $assigned_to
 */
class HousekeepingTask extends Model
{
    use BelongsToTenant, HasUuids;

    public const KINDS = ['checkout_clean', 'stayover', 'inspection', 'deep_clean', 'maintenance'];

    public const PENDING = 'pending';

    public const IN_PROGRESS = 'in_progress';

    public const DONE = 'done';

    public const INSPECTED = 'inspected';

    public const CANCELLED = 'cancelled';

    public const OPEN = [self::PENDING, self::IN_PROGRESS];

    protected $table = 'hotel_housekeeping_tasks';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
