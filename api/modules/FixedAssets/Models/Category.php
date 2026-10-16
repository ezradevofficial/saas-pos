<?php

namespace Modules\FixedAssets\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FA-02, FA-03: an asset category and its default depreciation terms (accounts come from Accounting's posting rules, keyed by its id). Audited as `fixed_assets.category.*`. */
class Category extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'fa_categories';

    protected string $auditModule = 'fixed_assets';

    protected string $auditResource = 'category';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime', 'useful_life_months' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
