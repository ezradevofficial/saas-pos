<?php

namespace Modules\Inventory\Dashboards;

use App\Core\Identity\Models\User;
use App\Core\Layouts\Dashboards\DashboardSource;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Inventory\Models\ReorderRule;
use Modules\Inventory\Stock\Qty;

/**
 * LAY-01, INV-22: items at or below their reorder level at the places the
 * reader sees with `inventory.stock.view` (RBAC-04), lowest first relative
 * to the level, with the total. Registered by the Inventory module, so it
 * exists only while the module is active (RBAC-08).
 */
class LowStock extends DashboardSource
{
    public function key(): string
    {
        return 'inventory.low_stock';
    }

    public function module(): string
    {
        return InventoryServiceProvider::MODULE;
    }

    public function label(): string
    {
        return 'inventory.dashboards.low_stock';
    }

    public function widgets(): array
    {
        return [self::LIST, self::KPI];
    }

    public function permissions(): array
    {
        return ['inventory.stock.view'];
    }

    public function rules(): array
    {
        return ['limit' => ['sometimes', 'integer', 'between:1,20']];
    }

    public function data(User $user, array $params): array
    {
        $visible = app(ScopeResolver::class)->visibleIds($user, 'inventory.stock.view');
        $query = ReorderRule::query()->whereNull('inventory_reorder_rules.archived_at')
            ->leftJoin('inventory_balances', fn ($j) => $j->on('inventory_balances.item_id', '=', 'inventory_reorder_rules.item_id')
                ->on('inventory_balances.location_id', '=', 'inventory_reorder_rules.location_id'))
            ->whereRaw('coalesce(inventory_balances.qty, 0) <= inventory_reorder_rules.reorder_level');
        $visible->applyTo($query, Scope::LOCATION, 'inventory_reorder_rules.location_id');
        $total = (clone $query)->count();

        $rows = $query->with(['item', 'location'])
            ->orderByRaw('coalesce(inventory_balances.qty, 0) - inventory_reorder_rules.reorder_level')
            ->orderBy('inventory_reorder_rules.id')
            ->limit((int) ($params['limit'] ?? 5))
            ->get(['inventory_reorder_rules.*', 'inventory_balances.qty as on_hand']);

        return [
            'kind' => 'number',
            'value' => $total,
            'rows' => $rows->map(fn (ReorderRule $rule) => [
                'id' => $rule->id,
                'title' => (string) $rule->item?->name,
                'subtitle' => __('inventory.dashboards.low_stock_row', [
                    'location' => (string) $rule->location?->name,
                    'qty' => Qty::show($rule->getAttribute('on_hand') ?? '0'),
                    'level' => Qty::show($rule->reorder_level),
                ]),
                'to' => '/inventory/items/'.$rule->item_id,
                'at' => $rule->low_since?->toIso8601String(),
            ])->all(),
            'total' => $total,
            'to' => '/inventory/stock?flag=low',
        ];
    }
}
