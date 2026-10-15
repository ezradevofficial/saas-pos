<?php

namespace Modules\Manufacturing\CustomFields;

use App\Core\CustomFields\Entities\CustomFieldEntity;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Support\Places;

/**
 * CF-01, MFG-22: BOMs and recipes carry custom fields, seen by holders of
 * `manufacturing.bom.view` anywhere in the BOM's company (RBAC-04, MFG-21),
 * offered only while Manufacturing is active. Shown by the output item's
 * code and name.
 */
class BomEntity extends CustomFieldEntity
{
    public const KEY = 'manufacturing_bom';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'manufacturing.custom_fields.bom';
    }

    public function module(): ?string
    {
        return 'manufacturing';
    }

    public function model(): string
    {
        return Bom::class;
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function viewAny(User $actor): bool
    {
        return app(ScopeResolver::class)->can($actor, 'manufacturing.bom.view');
    }

    public function view(User $actor, Model $record): bool
    {
        return app(Places::class)->inCompany($actor, 'manufacturing.bom.view', (string) $record->getAttribute('company_id'));
    }

    public function writeAny(User $actor): bool
    {
        return app(ScopeResolver::class)->can($actor, 'manufacturing.bom.manage');
    }

    public function visible(Builder $query, User $actor): Builder
    {
        return app(Places::class)->filterCompanies($query, $actor, 'manufacturing.bom.view');
    }

    public function display(Model $record): string
    {
        $item = Item::query()->whereKey($record->getAttribute('item_id'))->first(['code', 'name']);

        return trim(($item?->code ?? '').' '.($item?->name ?? '').' · '.__('manufacturing.kinds.'.$record->getAttribute('kind')));
    }

    protected function matching(Builder $query, string $search): void
    {
        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', $like)
                ->orWhereIn('item_id', fn ($items) => $items->select('id')->from('items')->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like)));
        }

        $query->orderBy('created_at')->orderBy('id');
    }
}
