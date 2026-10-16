<?php

namespace Modules\FixedAssets\Models;

use App\Core\Audit\Audited;
use App\Core\Currency\FxSnapshot;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FA-19: a maintenance work order of an asset, with its cost. Audited as `fixed_assets.work_order.*`. */
class WorkOrder extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'fa_work_orders';

    protected string $auditModule = 'fixed_assets';

    protected string $auditResource = 'work_order';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_on' => 'immutable_date', 'completed_on' => 'immutable_date', 'fx' => FxSnapshot::class.':fx'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class, 'schedule_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'vendor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
