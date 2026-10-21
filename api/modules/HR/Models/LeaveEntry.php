<?php

namespace Modules\HR\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-13: one movement of the leave ledger (append-only: a trigger refuses
 * updates and deletes). `key` makes runs and requests post once.
 */
class LeaveEntry extends Model
{
    use BelongsToTenant, HasUuids;

    public const KINDS = ['accrual', 'adjustment', 'taken', 'reversal', 'carry_over', 'expiry'];

    public const UPDATED_AT = null;

    protected $table = 'hr_leave_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'created_at' => 'immutable_datetime', 'researched' => 'boolean'];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
