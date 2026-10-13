<?php

namespace App\Core\Billing\Catalogue\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SUB-01: a bundle (Starter, Business, Enterprise): the modules it
 * includes, the add-ons sold with it, the outlets its price covers, and
 * whether it is quoted by sales instead of priced. Global (ADR 013).
 *
 * @property string $id
 * @property string $key
 * @property array{en: string, fr: string} $name
 * @property list<string> $modules
 * @property list<string> $addons
 * @property int $included_outlets
 * @property bool $quoted
 * @property int $sort
 * @property \Illuminate\Support\Carbon|null $archived_at
 */
class CatalogueBundle extends Model
{
    use HasUuids;

    protected $table = 'catalogue_bundles';

    protected $fillable = ['key', 'name', 'modules', 'addons', 'included_outlets', 'quoted', 'sort'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'modules' => 'array',
            'addons' => 'array',
            'included_outlets' => 'integer',
            'quoted' => 'boolean',
            'sort' => 'integer',
            'archived_at' => 'datetime',
        ];
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
