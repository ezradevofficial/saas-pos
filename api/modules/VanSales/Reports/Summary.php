<?php

namespace Modules\VanSales\Reports;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use Illuminate\Support\Collection;
use Modules\Inventory\Contracts\StockLevels;
use Modules\VanSales\Models\Collection as CollectionModel;
use Modules\VanSales\Models\Count;
use Modules\VanSales\Models\CountLine;
use Modules\VanSales\Models\CountTender;
use Modules\VanSales\Models\CreditEntry;
use Modules\VanSales\Models\Load;
use Modules\VanSales\Models\LoadLine;
use Modules\VanSales\Models\Route;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\Van;
use Modules\VanSales\Models\VanSale;
use Modules\VanSales\Models\Visit;
use Modules\VanSales\Support\Places;
use Modules\VanSales\Support\Qty;

/**
 * VAN-25: reports over the runs of a period (by their date), at the places
 * the reader may see (`van_sales.report.view`, RBAC-04): sales per route and
 * rep, strike rate (visits that sold ÷ visits), collections, variances,
 * credit outstanding, and stock on the vans (Inventory's levels).
 */
class Summary
{
    public function __construct(private readonly Places $places, private readonly StockLevels $levels) {}

    /** @param array{from: string, to: string, company_id?: ?string, branch_id?: ?string, route_id?: ?string, rep_id?: ?string, van_id?: ?string} $filters */
    public function summary(User $reader, string $permission, array $filters): array
    {
        $runs = Run::query()->whereBetween('run_date', [$filters['from'], $filters['to']]);
        $this->places->filterLocations($runs, $reader, $permission);

        foreach (['company_id', 'branch_id', 'route_id', 'rep_id', 'van_id'] as $key) {
            if (! empty($filters[$key])) {
                $runs->where($key, $filters[$key]);
            }
        }

        $runs = $runs->get();
        $ids = $runs->pluck('id');
        $visits = Visit::query()->whereIn('run_id', $ids)->get()->groupBy('run_id');
        $sales = VanSale::query()->whereIn('run_id', $ids)->where('status', 'completed')->get()->groupBy('run_id');
        $collections = CollectionModel::query()->whereIn('run_id', $ids)->where('status', 'recorded')->get()->groupBy('run_id');
        $routes = Route::query()->whereKey($runs->pluck('route_id')->filter()->unique()->all())->pluck('name', 'id');
        $reps = User::query()->whereKey($runs->pluck('rep_id')->unique()->all())->pluck('name', 'id');
        $rows = [];

        foreach ($runs as $run) {
            $key = ($run->route_id ?? '').'|'.$run->rep_id;
            $rows[$key] ??= ['route_id' => $run->route_id, 'route_name' => $routes[$run->route_id] ?? null, 'rep_id' => $run->rep_id, 'rep_name' => $reps[$run->rep_id] ?? null,
                'visits' => 0, 'sold_visits' => 0, 'sales_count' => 0, 'sales' => [], 'collections' => []];
            $runVisits = $visits[$run->id] ?? collect();
            $rows[$key]['visits'] += $runVisits->count();
            $rows[$key]['sold_visits'] += $runVisits->where('status', 'sold')->count();

            foreach ($sales[$run->id] ?? [] as $sale) {
                $rows[$key]['sales_count']++;
                $rows[$key]['sales'][$sale->currency] = ($rows[$key]['sales'][$sale->currency] ?? 0) + (int) $sale->total_minor;
            }

            foreach ($collections[$run->id] ?? [] as $collection) {
                $rows[$key]['collections'][$collection->currency] = ($rows[$key]['collections'][$collection->currency] ?? 0) + (int) $collection->amount_minor;
            }
        }

        $rows = collect($rows)->sortBy(fn ($row) => [$row['route_name'] ?? '', $row['rep_name'] ?? ''])->map(fn (array $row) => [
            ...$row,
            'strike_rate' => $row['visits'] === 0 ? 0.0 : round($row['sold_visits'] / $row['visits'] * 100, 1),
            'sales' => $this->money($row['sales'], 'total_minor'),
            'collections' => $this->money($row['collections'], 'amount_minor'),
        ])->values()->all();

        return [
            'from' => $filters['from'],
            'to' => $filters['to'],
            'rows' => $rows,
            'cash_variances' => $this->cashVariances($runs),
            'stock_variances' => $this->stockVariances($runs),
            'credit_outstanding' => $this->creditOutstanding($reader, $permission),
        ];
    }

    /** @param array<string, int> $totals @return list<array<string, string>> */
    private function money(array $totals, string $field): array
    {
        ksort($totals);

        return array_map(fn (string $currency, int $amount) => ['currency' => $currency, $field => (string) $amount], array_keys($totals), array_values($totals));
    }

    /** @param Collection<int, Run> $runs */
    private function cashVariances(Collection $runs): array
    {
        $counts = $this->latestCounts($runs);
        $vans = Van::query()->whereKey($runs->pluck('van_id')->unique()->all())->pluck('name', 'id');
        $reps = User::query()->whereKey($runs->pluck('rep_id')->unique()->all())->pluck('name', 'id');
        $byRun = $runs->keyBy('id');

        return CountTender::query()->whereIn('count_id', $counts->pluck('id'))->where('variance_minor', '<>', 0)->orderBy('currency')->orderBy('kind')->get()
            ->map(function (CountTender $t) use ($counts, $byRun, $vans, $reps) {
                $run = $byRun[$counts[$t->count_id]->run_id];

                return ['run_id' => $run->id, 'run_date' => $run->run_date->toDateString(), 'van_name' => $vans[$run->van_id] ?? null, 'rep_name' => $reps[$run->rep_id] ?? null,
                    'kind' => $t->kind, 'currency' => $t->currency, 'variance_minor' => (string) $t->variance_minor];
            })->sortBy('run_date')->values()->all();
    }

    /** @param Collection<int, Run> $runs */
    private function stockVariances(Collection $runs): array
    {
        $counts = $this->latestCounts($runs);
        $vans = Van::query()->whereKey($runs->pluck('van_id')->unique()->all())->pluck('name', 'id');
        $reps = User::query()->whereKey($runs->pluck('rep_id')->unique()->all())->pluck('name', 'id');
        $byRun = $runs->keyBy('id');
        $lines = CountLine::query()->whereIn('count_id', $counts->pluck('id'))->where('variance_qty', '<>', 0)->get();
        $items = Item::query()->whereKey($lines->pluck('item_id')->unique()->all())->pluck('name', 'id');

        return $lines->map(function (CountLine $l) use ($counts, $byRun, $vans, $reps, $items) {
            $run = $byRun[$counts[$l->count_id]->run_id];

            return ['run_id' => $run->id, 'run_date' => $run->run_date->toDateString(), 'van_name' => $vans[$run->van_id] ?? null, 'rep_name' => $reps[$run->rep_id] ?? null,
                'item_id' => $l->item_id, 'item_name' => $items[$l->item_id] ?? null, 'variance_qty' => Qty::show((string) $l->variance_qty)];
        })->sortBy('run_date')->values()->all();
    }

    /** The latest count of each run. @param Collection<int, Run> $runs @return Collection<string, Count> keyed by count id */
    private function latestCounts(Collection $runs): Collection
    {
        return Count::query()->whereIn('run_id', $runs->pluck('id'))->orderBy('attempt')->get()->groupBy('run_id')->map(fn ($group) => $group->last())->keyBy('id');
    }

    private function creditOutstanding(User $reader, string $permission): array
    {
        $query = CreditEntry::query();
        $this->places->filterCompanies($query, $reader, $permission);
        $rows = $query->groupBy('currency')->selectRaw('currency, sum(amount_minor) as amount')->orderBy('currency')->get();

        return $rows->map(fn ($row) => ['currency' => $row->currency, 'amount_minor' => (string) (int) $row->amount])->all();
    }

    /** VAN-25: the stock on each van the reader sees, for the items last loaded onto it. */
    public function vanStock(User $reader, string $permission): array
    {
        $vans = Van::query()->whereNull('archived_at')->orderBy('name');
        $this->places->filterLocations($vans, $reader, $permission);
        $vans = $vans->get();
        $data = [];
        $inventory = true;

        foreach ($vans as $van) {
            $loadIds = Load::query()->where('van_id', $van->id)->where('state', 'confirmed')->orderByDesc('confirmed_at')->limit(5)->pluck('id');
            $itemIds = LoadLine::query()->whereIn('load_id', $loadIds)->distinct()->pluck('item_id')->all();
            $levels = $this->levels->onHand($van->location_id, $itemIds);

            if ($levels === null) {
                $inventory = false;
                $levels = [];
            }

            $names = Item::query()->whereKey($itemIds)->pluck('name', 'id');
            $data[] = ['van_id' => $van->id, 'van_name' => $van->name, 'items' => collect($levels)->map(fn (string $qty, string $itemId) => [
                'item_id' => $itemId, 'item_name' => $names[$itemId] ?? null, 'qty' => Qty::show($qty),
            ])->sortBy('item_name')->values()->all()];
        }

        return ['data' => $data, 'inventory' => $inventory];
    }
}
