<?php

namespace Modules\FixedAssets\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** FA-12, FA-13: a company's depreciation run of one month. Audited as `fixed_assets.run.*`. */
class DepreciationRun extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'fa_depreciation_runs';

    protected string $auditModule = 'fixed_assets';

    protected string $auditResource = 'run';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period' => 'immutable_date', 'period_end' => 'immutable_date', 'posted_at' => 'immutable_datetime', 'reversed_at' => 'immutable_datetime', 'total_minor' => 'integer', 'assets_count' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DepreciationLine::class, 'run_id');
    }
}
