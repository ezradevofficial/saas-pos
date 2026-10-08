<?php

namespace App\Core\MasterData\Taxes;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The default tax code of a tax category in one company (MD-03). The
 * database ties the code to that company (composite key). Changes are
 * audited on the category as `core.tax_category.codes_update`.
 */
class TaxCategoryCode extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tax_category_id', 'company_id', 'tax_code_id'];

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
