<?php

namespace App\Core\CountryPacks\Models;

use App\Core\CountryPacks\PackLabels;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One published version of a country pack (CP-01, CP-03): global reference
 * data, read by every tenant, written only by `country-packs:publish` as the
 * schema owner. The summary holds the pack's notes, sources, the
 * figures still to confirm (`todo`) and the change from the previous version.
 */
class CountryPack extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'version', 'content_hash', 'published_at', 'summary'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'immutable_datetime',
            'summary' => 'array',
        ];
    }

    public function taxCodes(): HasMany
    {
        return $this->hasMany(PackTaxCode::class)->orderBy('code')->orderBy('effective_from');
    }

    /** The latest version of the pack for $code, or null when none is published. */
    public static function latest(string $code): ?self
    {
        return static::query()->where('code', $code)->orderByDesc('version')->first();
    }

    public function name(?string $locale = null): string
    {
        return PackLabels::pack($this->code, $locale);
    }
}
