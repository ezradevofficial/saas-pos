<?php

namespace Modules\Inventory\Dashboards;

use App\Core\Identity\Models\User;
use App\Core\Layouts\Dashboards\DashboardSource;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Inventory\Models\CostState;

/**
 * LAY-01, INV-22, INV-20: the value of the stock of the companies where
 * the reader holds `inventory.valuation.view` at the company or above (a
 * company's cost is pooled over its locations), in the base currency. When
 * those companies use different base currencies, the largest is shown and
 * `complete` is false (no conversion is made here).
 */
class StockValue extends DashboardSource
{
    public function key(): string
    {
        return 'inventory.stock_value';
    }

    public function module(): string
    {
        return InventoryServiceProvider::MODULE;
    }

    public function label(): string
    {
        return 'inventory.dashboards.stock_value';
    }

    public function widgets(): array
    {
        return [self::KPI];
    }

    public function permissions(): array
    {
        return ['inventory.valuation.view'];
    }

    public function data(User $user, array $params): array
    {
        $visible = app(ScopeResolver::class)->visibleIds($user, 'inventory.valuation.view');
        $query = CostState::query();

        if (! $visible->all) {
            $query->whereIn('company_id', $visible->idsFor(Scope::COMPANY));
        }

        $totals = $query->selectRaw('currency, sum(value_minor) as value, count(distinct item_id) filter (where qty <> 0) as items')
            ->groupBy('currency')->orderByRaw('sum(value_minor) desc')->get();
        $first = $totals->first();

        return [
            'kind' => 'money',
            'value' => $first === null ? '0' : (string) $first->value,
            'currency' => $first?->currency,
            'count' => (int) ($first?->items ?? 0),
            'complete' => $totals->count() <= 1,
            'to' => '/inventory/valuation',
        ];
    }
}
