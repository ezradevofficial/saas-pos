<?php

namespace Modules\FixedAssets\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FA-19: a time- or usage-based maintenance schedule of an asset. Audited as `fixed_assets.schedule.*`. */
class MaintenanceSchedule extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'fa_maintenance_schedules';

    protected string $auditModule = 'fixed_assets';

    protected string $auditResource = 'schedule';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_done_on' => 'immutable_date', 'next_due_on' => 'immutable_date', 'archived_at' => 'immutable_datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
