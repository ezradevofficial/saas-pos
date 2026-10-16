<?php

namespace Modules\Manufacturing\CustomFields;

use App\Core\CustomFields\Entities\CustomFieldEntity;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Support\Places;

/**
 * CF-01, MFG-22: production orders carry custom fields (also on the
 * production order print), seen where the reader holds
 * `manufacturing.order.view` at the order's location (RBAC-04), offered
 * only while Manufacturing is active. Shown by number. Orders are
 * cancelled, not archived: unique values across them are not offered.
 */
class OrderEntity extends CustomFieldEntity
{
    public const KEY = 'manufacturing_order';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'manufacturing.custom_fields.order';
    }

    public function module(): ?string
    {
        return 'manufacturing';
    }

    public function model(): string
    {
        return Order::class;
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function allowsUnique(): bool
    {
        return false;
    }

    public function viewAny(User $actor): bool
    {
        return app(ScopeResolver::class)->can($actor, 'manufacturing.order.view');
    }

    public function view(User $actor, Model $record): bool
    {
        return app(Places::class)->at($actor, 'manufacturing.order.view', (string) $record->getAttribute('location_id'));
    }

    public function writeAny(User $actor): bool
    {
        return app(ScopeResolver::class)->can($actor, 'manufacturing.order.create');
    }

    public function visible(Builder $query, User $actor): Builder
    {
        return app(Places::class)->filterLocations($query, $actor, 'manufacturing.order.view');
    }

    public function display(Model $record): string
    {
        return (string) $record->getAttribute('number');
    }

    public function search(User $actor, string $search, int $limit): array
    {
        if (! $this->viewAny($actor)) {
            return [];
        }

        $query = $this->visible($this->newQuery(), $actor)->where('status', '<>', Order::CANCELLED);

        if (trim($search) !== '') {
            $query->where('number', 'ilike', '%'.addcslashes(trim($search), '\\%_').'%');
        }

        return $query->orderByDesc('created_at')->orderBy('id')->limit($limit)->get()
            ->map(fn (Model $record) => ['id' => (string) $record->getKey(), 'label' => $this->display($record)])->values()->all();
    }
}
