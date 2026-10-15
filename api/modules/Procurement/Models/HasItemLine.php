<?php

namespace Modules\Procurement\Models;

use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\MasterData\Dimensions\Project;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Taxes\TaxCode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The core records a procurement line may name (MD-02, MD-03, MD-05). */
trait HasItemLine
{
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
