<?php

namespace Modules\FixedAssets\Models;

use App\Core\Audit\Audited;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FA-21: an item flagged as an asset in a company, with its default category. Audited as `fixed_assets.item_category.*`. */
class ItemCategory extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'fa_item_categories';

    protected string $auditModule = 'fixed_assets';

    protected string $auditResource = 'item_category';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
