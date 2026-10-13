<?php

namespace App\Core\Billing\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The countries the platform sells in and their billing currency (KE in
 * KES, CD in USD: software in the DRC is bought in dollars, concept note
 * 8.4). Read-only at runtime; changed by migration.
 *
 * @property string $country
 * @property string $currency
 */
class CatalogueCountry extends Model
{
    protected $table = 'catalogue_countries';

    protected $primaryKey = 'country';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @return array<string, string> country => currency */
    public static function currencies(): array
    {
        return self::query()->orderBy('country')->pluck('currency', 'country')->all();
    }
}
