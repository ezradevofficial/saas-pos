<?php

namespace App\Core\CustomFields\Entities;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemPolicy;
use App\Core\MasterData\SharedRecordPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** CF-01: items (MD-02) carry custom fields; synced to the till as `items`. */
class ItemEntity extends SharedRecordEntity
{
    public const KEY = 'item';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'core.custom_field.entities.item';
    }

    public function model(): string
    {
        return Item::class;
    }

    public function fieldRules(): string
    {
        return 'item';
    }

    protected function policy(): SharedRecordPolicy
    {
        return app(ItemPolicy::class);
    }

    protected function permission(): string
    {
        return 'core.item';
    }

    /** @param Item $record */
    public function display(Model $record): string
    {
        return strtoupper((string) $record->code).' · '.$record->name;
    }

    public function syncedRecords(): Builder
    {
        return Item::query();
    }

    protected function matching(Builder $query, string $search): void
    {
        if ($search !== '') {
            $query->where(fn (Builder $q) => $q
                ->whereRaw('lower(code::text) like ?', [addcslashes(mb_strtolower($search), '\\%_').'%'])
                ->orWhere('name', 'ilike', '%'.addcslashes($search, '\\%_').'%'));
        }

        $query->orderBy('code')->orderBy('id');
    }
}
