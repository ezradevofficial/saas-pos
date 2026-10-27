<?php

namespace Modules\Restaurant\Sync\Sources;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\Restaurant\Models\ModifierGroup;
use Modules\Restaurant\RestaurantServiceProvider;

/** REST-07, NFR-04: the company's modifier groups (archived ones are left out). */
class ModifierSource implements SnapshotSource
{
    public function key(): string
    {
        return 'restaurant_modifiers';
    }

    public function module(): string
    {
        return RestaurantServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        return ModifierGroup::query()->where('company_id', $scope->company->id)->whereNull('archived_at')
            ->with(['modifiers' => fn ($q) => $q->whereNull('archived_at'), 'links'])->orderBy('name')->orderBy('id')->get()
            ->map(fn (ModifierGroup $g) => [
                'id' => $g->id, 'name' => $g->name, 'min_select' => (int) $g->min_select, 'max_select' => (int) $g->max_select, 'currency' => $g->currency,
                'item_ids' => $g->links->pluck('item_id')->filter()->sort()->values()->all(),
                'category_ids' => $g->links->pluck('item_category_id')->filter()->sort()->values()->all(),
                'modifiers' => $g->modifiers->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'price_minor' => (string) $m->price_minor, 'sort' => (int) $m->sort])->all(),
            ])->all();
    }
}
