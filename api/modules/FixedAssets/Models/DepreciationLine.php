<?php

namespace Modules\FixedAssets\Models;

use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FA-12: an asset's charge in a run (the run itself is audited). */
class DepreciationLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'fa_depreciation_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_date' => 'immutable_date', 'to_date' => 'immutable_date', 'previous_depreciated_to' => 'immutable_date', 'amount_minor' => 'integer'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DepreciationRun::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }
}
