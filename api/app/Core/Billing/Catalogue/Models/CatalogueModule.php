<?php

namespace App\Core\Billing\Catalogue\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SUB-01: a module (or extra, or add-on) of the plan catalogue. Global
 * (ADR 013). The key matches the module registry; the name is held in
 * English and French because platform staff write it for every tenant.
 *
 * @property string $id
 * @property string $key
 * @property string $kind
 * @property string $unit
 * @property array{en: string, fr: string} $name
 * @property array<string, int> $includes
 * @property string|null $requires
 * @property int $sort
 * @property Carbon|null $archived_at
 */
class CatalogueModule extends Model
{
    use HasUuids;

    protected $table = 'catalogue_modules';

    protected $fillable = ['key', 'kind', 'unit', 'name', 'includes', 'requires', 'sort'];

    protected function casts(): array
    {
        return ['name' => 'array', 'includes' => 'array', 'sort' => 'integer', 'archived_at' => 'datetime'];
    }

    public function scopeLive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function localName(?string $locale = null): string
    {
        return CatalogueText::pick($this->name, $locale);
    }
}
