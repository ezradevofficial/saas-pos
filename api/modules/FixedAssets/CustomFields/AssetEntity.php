<?php

namespace Modules\FixedAssets\CustomFields;

use App\Core\CustomFields\Entities\CustomFieldEntity;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\FixedAssets\FixedAssetsServiceProvider;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Support\Access;

/**
 * CF-01, FA-23: assets carry custom fields in their `custom` column, seen
 * where the reader holds `fixed_assets.asset.view` at the asset's location
 * (RBAC-04), written with `fixed_assets.asset.manage`. Shown by tag and
 * name. Offered only while the module is active (RBAC-08).
 */
class AssetEntity extends CustomFieldEntity
{
    public const KEY = 'fixed_assets_asset';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'fixed_assets.custom_fields.asset';
    }

    public function model(): string
    {
        return Asset::class;
    }

    public function module(): ?string
    {
        return FixedAssetsServiceProvider::MODULE;
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function viewAny(User $actor): bool
    {
        return app(ScopeResolver::class)->can($actor, 'fixed_assets.asset.view');
    }

    public function view(User $actor, Model $record): bool
    {
        return app(Access::class)->at($actor, 'fixed_assets.asset.view', $record->location_id);
    }

    public function writeAny(User $actor): bool
    {
        return app(ScopeResolver::class)->can($actor, 'fixed_assets.asset.manage');
    }

    public function visible(Builder $query, User $actor): Builder
    {
        return app(Access::class)->byLocation($query, $actor, 'fixed_assets.asset.view');
    }

    public function display(Model $record): string
    {
        return trim($record->number.' '.$record->name);
    }

    public function search(User $actor, string $search, int $limit): array
    {
        if (! $this->viewAny($actor)) {
            return [];
        }

        $query = $this->visible($this->newQuery(), $actor)->whereNull('archived_at');
        $search = trim($search);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($q) => $q->where('number', 'ilike', $like)->orWhere('name', 'ilike', $like));
        }

        return $query->orderBy('number')->limit($limit)->get()
            ->map(fn (Model $record) => ['id' => (string) $record->getKey(), 'label' => $this->display($record)])->values()->all();
    }
}
