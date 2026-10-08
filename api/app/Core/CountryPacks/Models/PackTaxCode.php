<?php

namespace App\Core\CountryPacks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tax code of a country pack version with one effective-dated rate
 * (CP-01, CP-02). A code may appear more than once in a version, once per
 * period. `rate` is a percentage, NULL when no figure is confirmed
 * (`needs_confirmation`); exempt codes have no rate.
 */
class PackTaxCode extends Model
{
    use HasUuids;

    protected $table = 'country_pack_tax_codes';

    protected $fillable = [
        'country_pack_id', 'code', 'name_en', 'name_fr', 'kind', 'rate',
        'needs_confirmation', 'effective_from', 'effective_to', 'fiscal_code',
    ];

    protected function casts(): array
    {
        return [
            'needs_confirmation' => 'boolean',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
        ];
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(CountryPack::class, 'country_pack_id');
    }
}
