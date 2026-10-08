<?php

namespace App\Core\Sync\Sources;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\IncrementalSource;
use App\Core\Sync\DeviceScope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MD-02, NFR-04: item categories (a tree) for the till's catalogue: the
 * group's shared ones and the device's company's, active only. `colour`
 * is a design token name (LAY-05), never a colour value.
 */
class ItemCategorySource implements IncrementalSource
{
    public function key(): string
    {
        return 'item_categories';
    }

    public function module(): string
    {
        return ModuleRegistry::CORE;
    }

    public function version(): int
    {
        return 1;
    }

    public function table(): string
    {
        return 'item_categories';
    }

    public function visible(Builder $query, DeviceScope $scope): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('item_categories.company_id')->orWhere('item_categories.company_id', $scope->companyId()));
    }

    public function rows(array $ids, DeviceScope $scope): array
    {
        return $this->visible(DB::connection(TenantContext::CONNECTION)->table('item_categories'), $scope)
            ->whereIn('item_categories.id', $ids)
            ->whereNull('item_categories.archived_at')
            ->get(['id', 'company_id', 'parent_id', 'name', 'colour', 'updated_at'])
            ->mapWithKeys(fn (object $c) => [$c->id => [
                'id' => $c->id,
                'parent_id' => $c->parent_id,
                'name' => $c->name,
                'colour' => $c->colour,
                'shared' => $c->company_id === null,
                'updated_at' => Iso::of($c->updated_at),
            ]])
            ->all();
    }
}
