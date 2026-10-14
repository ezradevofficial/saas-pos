<?php

namespace Modules\Procurement\Models;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRC-02: a purchase requisition, approved through the
 * `procurement.requisition` flow.
 *
 * @property ?string $location_id
 * @property ?string $department_id
 * @property string $currency
 * @property string $requested_by
 * @property ?array $custom
 */
class Requisition extends ProcurementDocument
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const ORDERED = 'ordered';

    public const STATUSES = [self::DRAFT, self::PENDING, self::APPROVED, self::REJECTED, self::CANCELLED, self::ORDERED];

    protected $table = 'procurement_requisitions';

    protected string $auditResource = 'requisition';

    protected function casts(): array
    {
        return [
            'custom' => 'array', 'needed_by' => 'immutable_date', 'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'estimated_total_minor' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RequisitionLine::class)->orderBy('line_no');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
