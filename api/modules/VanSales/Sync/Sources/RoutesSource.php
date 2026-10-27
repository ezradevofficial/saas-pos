<?php

namespace Modules\VanSales\Sync\Sources;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\FieldRules;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\VanSales\Models\Route;
use Modules\VanSales\Models\Stop;
use Modules\VanSales\VanSalesServiceProvider;

/** VAN-03, VAN-04, NFR-04: the routes the van may run (its own and the branch's unassigned ones) with their stops and customers. */
class RoutesSource implements SnapshotSource
{
    public function key(): string
    {
        return 'van_sales_routes';
    }

    public function module(): string
    {
        return VanSalesServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $van = VanSource::vanOf($scope);

        if ($van === null) {
            return [];
        }

        $routes = Route::query()->whereNull('archived_at')->where('company_id', $van->company_id)->where('branch_id', $van->branch_id)
            ->where(fn ($q) => $q->where('van_id', $van->id)->orWhereNull('van_id'))->orderBy('name')->orderBy('id')->get();
        $stops = Stop::query()->whereNull('archived_at')->whereIn('route_id', $routes->pluck('id'))->orderBy('position')->orderBy('id')->get()->groupBy('route_id');
        $parties = Party::query()->whereKey($stops->flatten()->pluck('customer_id')->unique()->all())->get()->keyBy('id');
        // RBAC-05: contacts only if the rep's role may see them (core.party.view at the van, the `phones` field not hidden).
        $rep = User::query()->find($van->rep_id);
        $contacts = $rep !== null && app(ScopeResolver::class)->can($rep, 'core.party.view', Scope::location($van->location_id))
            && ! in_array('phones', app(FieldRules::class)->for($rep, 'party')['hidden'], true);

        return $routes->map(fn (Route $route) => [
            'id' => $route->id,
            'name' => $route->name,
            'visit_days' => array_values($route->visit_days ?? []),
            'stops' => ($stops[$route->id] ?? collect())->map(fn (Stop $stop) => [
                'id' => $stop->id,
                'customer_id' => $stop->customer_id,
                'customer_name' => $parties->get($stop->customer_id)?->name,
                'phone' => $contacts ? ($parties->get($stop->customer_id)?->phones[0]['number'] ?? null) : null,
                'position' => (int) $stop->position,
                'visit_days' => $stop->visit_days,
            ])->values()->all(),
        ])->values()->all();
    }
}
