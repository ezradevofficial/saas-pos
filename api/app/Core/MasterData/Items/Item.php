<?php

namespace App\Core\MasterData\Items;

use App\Core\Audit\Audited;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MD-02: an item of the catalogue (core part): code, names in English and
 * French, category, type, base unit and other units, barcodes, tax
 * category and images. Modules add their own fields (Inventory costing,
 * kit components). Shared across the group when company_id is null, else
 * one company's, following the items sharing mode (TEN-08). The code is
 * case-insensitive and unique among active items in the sharing scope, as
 * are barcodes (review focus 4). `custom` holds custom field values
 * (CF-06, later). Archived, never deleted (TEN-06); audited as
 * `core.item.*` (MD-07).
 *
 * Not HasScope: who reaches an item is decided by ItemPolicy.
 */
#[UsePolicy(ItemPolicy::class)]
class Item extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const TYPES = ['stock', 'service', 'non_stock', 'kit'];

    public const MAX_IMAGES = 8;

    protected $fillable = ['company_id', 'code', 'name', 'category_id', 'type', 'base_uom_id', 'tax_category_id'];

    protected $attributes = ['custom' => '{}'];

    protected function casts(): array
    {
        return ['custom' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'base_uom_id');
    }

    public function taxCategory(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class);
    }

    public function uoms(): HasMany
    {
        return $this->hasMany(ItemUom::class)->orderBy('factor')->orderBy('id');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class)->orderBy('barcode');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ItemImage::class)->orderBy('position');
    }

    public function isShared(): bool
    {
        return $this->company_id === null;
    }
}
