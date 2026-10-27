<?php

namespace Modules\VanSales\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use Illuminate\Support\Collection;
use Modules\VanSales\Credit\Ledger;
use Modules\VanSales\Loads\Loads;
use Modules\VanSales\Models\Collection as CollectionModel;
use Modules\VanSales\Models\Count;
use Modules\VanSales\Models\CountLine;
use Modules\VanSales\Models\CountTender;
use Modules\VanSales\Models\CreditEntry;
use Modules\VanSales\Models\Load;
use Modules\VanSales\Models\LoadLine;
use Modules\VanSales\Models\Route;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\Stop;
use Modules\VanSales\Models\Van;
use Modules\VanSales\Models\VanSale;
use Modules\VanSales\Models\Visit;
use Modules\VanSales\Reconciliation\Unloads;
use Modules\VanSales\Support\Qty;

/**
 * The shapes of docs/modules/van-sales.md (Response shapes), built with the
 * names looked up once per list. Check-in positions are never in them
 * (VAN-24): only the positions route reads them.
 */
class Views
{
    public function __construct(
        private readonly Loads $loads,
        private readonly Unloads $unloads,
        private readonly Ledger $ledger,
    ) {}

    private function iso($at): ?string
    {
        return $at?->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    /** @param Collection<int, mixed> $ids @return Collection<string, string> */
    private function names(string $model, Collection|array $ids): Collection
    {
        $ids = collect($ids)->filter()->unique()->values()->all();

        return $ids === [] ? collect() : $model::query()->whereKey($ids)->pluck('name', 'id');
    }

    /** @param Collection<int, Van> $vans */
    public function vans(Collection $vans): array
    {
        $reps = $this->names(User::class, $vans->pluck('rep_id'));
        $locations = $this->names(Location::class, $vans->pluck('location_id'));
        $devices = $this->names(Device::class, $vans->pluck('device_id'));

        return $vans->map(fn (Van $van) => [
            'id' => $van->id, 'company_id' => $van->company_id, 'branch_id' => $van->branch_id, 'location_id' => $van->location_id,
            'location_name' => $locations[$van->location_id] ?? null, 'name' => $van->name, 'plate' => $van->plate,
            'rep_id' => $van->rep_id, 'rep_name' => $reps[$van->rep_id] ?? null, 'device_id' => $van->device_id,
            'device_name' => $devices[$van->device_id] ?? null, 'archived_at' => $this->iso($van->archived_at),
        ])->values()->all();
    }

    public function van(Van $van): array
    {
        return $this->vans(collect([$van]))[0];
    }

    /** @param Collection<int, Route> $routes */
    public function routes(Collection $routes, bool $detail = false): array
    {
        $vans = $this->names(Van::class, $routes->pluck('van_id'));
        $stops = Stop::query()->whereNull('archived_at')->whereIn('route_id', $routes->pluck('id'))->orderBy('position')->orderBy('id')->get()->groupBy('route_id');
        $customers = $detail ? $this->names(Party::class, $stops->flatten()->pluck('customer_id')) : collect();

        return $routes->map(fn (Route $route) => [
            'id' => $route->id, 'company_id' => $route->company_id, 'branch_id' => $route->branch_id, 'van_id' => $route->van_id,
            'van_name' => $vans[$route->van_id] ?? null, 'name' => $route->name, 'visit_days' => array_values($route->visit_days ?? []),
            'stop_count' => ($stops[$route->id] ?? collect())->count(), 'archived_at' => $this->iso($route->archived_at),
            ...($detail ? ['stops' => ($stops[$route->id] ?? collect())->map(fn (Stop $stop) => [
                'id' => $stop->id, 'customer_id' => $stop->customer_id, 'customer_name' => $customers[$stop->customer_id] ?? null,
                'position' => (int) $stop->position, 'visit_days' => $stop->visit_days,
            ])->values()->all()] : []),
        ])->values()->all();
    }

    /** @param Collection<int, Run> $runs */
    public function runs(Collection $runs, bool $detail = false): array
    {
        $vans = $this->names(Van::class, $runs->pluck('van_id'));
        $routes = $this->names(Route::class, $runs->pluck('route_id'));
        $reps = $this->names(User::class, $runs->pluck('rep_id'));
        $ids = $runs->pluck('id');
        $visits = Visit::query()->whereIn('run_id', $ids)->orderBy('device_updated_at')->orderBy('id')->get()->groupBy('run_id');
        $sales = VanSale::query()->whereIn('run_id', $ids)->orderBy('sold_at')->orderBy('id')->get()->groupBy('run_id');
        $counts = Count::query()->whereIn('run_id', $ids)->orderBy('attempt')->get()->groupBy('run_id');
        $customers = $detail ? $this->names(Party::class, [...$visits->flatten()->pluck('customer_id')->all(), ...$sales->flatten()->pluck('customer_id')->all()]) : collect();

        return $runs->map(function (Run $run) use ($vans, $routes, $reps, $visits, $sales, $counts, $customers, $detail) {
            $runVisits = $visits[$run->id] ?? collect();
            $runSales = ($sales[$run->id] ?? collect())->where('status', 'completed');
            $count = ($counts[$run->id] ?? collect())->last();
            $row = [
                'id' => $run->id, 'van_id' => $run->van_id, 'van_name' => $vans[$run->van_id] ?? null, 'route_id' => $run->route_id,
                'route_name' => $routes[$run->route_id] ?? null, 'rep_id' => $run->rep_id, 'rep_name' => $reps[$run->rep_id] ?? null,
                'run_date' => $run->run_date->toDateString(), 'status' => $run->status, 'started_at' => $this->iso($run->started_at),
                'closed_at' => $this->iso($run->closed_at), 'visit_count' => $runVisits->count(), 'sold_visit_count' => $runVisits->where('status', 'sold')->count(),
                'sales_count' => $runSales->count(),
                'sales_totals' => $runSales->groupBy('currency')->map(fn ($group, $currency) => ['currency' => $currency, 'total_minor' => (string) $group->sum('total_minor')])->sortKeys()->values()->all(),
                'reconciliation_status' => $count?->status,
            ];

            if ($detail) {
                $row['visits'] = $runVisits->map(fn (Visit $visit) => [
                    'id' => $visit->id, 'stop_id' => $visit->stop_id, 'customer_id' => $visit->customer_id, 'customer_name' => $customers[$visit->customer_id] ?? null,
                    'status' => $visit->status, 'no_sale_reason' => $visit->no_sale_reason, 'note' => $visit->note,
                    'arrived_at' => $this->iso($visit->arrived_at), 'left_at' => $this->iso($visit->left_at),
                ])->values()->all();
                $row['sales'] = ($sales[$run->id] ?? collect())->map(fn (VanSale $sale) => [
                    'id' => $sale->id, 'sale_id' => $sale->sale_id, 'visit_id' => $sale->visit_id, 'customer_id' => $sale->customer_id,
                    'customer_name' => $customers[$sale->customer_id] ?? null, 'currency' => $sale->currency, 'total_minor' => (string) $sale->total_minor,
                    'credit_minor' => (string) $sale->credit_minor, 'status' => $sale->status, 'flags' => array_values($sale->flags ?? []),
                    'review_status' => $sale->review_status, 'sold_at' => $this->iso($sale->sold_at),
                ])->values()->all();
                $row['collections'] = $this->collections(CollectionModel::query()->where('run_id', $run->id)->orderBy('collected_at')->get());
                $row['loads'] = $this->loads(Load::query()->where('van_id', $run->van_id)->where('run_id', $run->id)->orderBy('created_at')->get());
                $row['reconciliation'] = $count === null ? null : $this->reconciliation($count, true);
            }

            return $row;
        })->values()->all();
    }

    /** @param Collection<int, Load> $loads */
    public function loads(Collection $loads): array
    {
        $vans = $this->names(Van::class, $loads->pluck('van_id'));
        $locations = $this->names(Location::class, $loads->pluck('from_location_id'));
        $users = $this->names(User::class, $loads->pluck('requested_by'));
        $lines = LoadLine::query()->whereIn('load_id', $loads->pluck('id'))->orderBy('created_at')->orderBy('id')->get()->groupBy('load_id');
        $items = $this->names(Item::class, $lines->flatten()->pluck('item_id'));
        $statuses = $this->loads->statuses($loads);

        return $loads->map(fn (Load $load) => [
            'id' => $load->id, 'van_id' => $load->van_id, 'van_name' => $vans[$load->van_id] ?? null, 'run_id' => $load->run_id,
            'from_location_id' => $load->from_location_id, 'from_location_name' => $locations[$load->from_location_id] ?? null,
            'number' => $load->number, 'status' => $statuses[$load->id], 'note' => $load->note,
            'requested_by_name' => $users[$load->requested_by] ?? null, 'requested_at' => $this->iso($load->created_at),
            'released_at' => $this->iso($load->released_at), 'confirmed_at' => $this->iso($load->confirmed_at),
            'lines' => ($lines[$load->id] ?? collect())->map(fn (LoadLine $line) => [
                'id' => $line->id, 'item_id' => $line->item_id, 'item_name' => $items[$line->item_id] ?? null,
                'qty' => Qty::show((string) $line->qty), 'qty_confirmed' => $line->qty_confirmed === null ? null : Qty::show((string) $line->qty_confirmed),
            ])->values()->all(),
        ])->values()->all();
    }

    /** @param Collection<int, CollectionModel> $collections */
    public function collections(Collection $collections): array
    {
        $customers = $this->names(Party::class, $collections->pluck('customer_id'));

        return $collections->map(fn (CollectionModel $c) => [
            'id' => $c->id, 'run_id' => $c->run_id, 'customer_id' => $c->customer_id, 'customer_name' => $customers[$c->customer_id] ?? null,
            'currency' => $c->currency, 'amount_minor' => (string) $c->amount_minor, 'method_type' => $c->method_type,
            'receipt_number' => $c->receipt_number, 'provider_reference' => $c->provider_reference, 'collected_at' => $this->iso($c->collected_at),
            'status' => $c->status, 'flags' => array_values($c->flags ?? []), 'void_reason' => $c->void_reason,
        ])->values()->all();
    }

    /** @param Collection<int, Party> $parties @param list<string>|null $companyIds the balances and entries of these companies only (RBAC-04) */
    public function accounts(Collection $parties, bool $detail = false, ?array $companyIds = null): array
    {
        return $parties->map(fn (Party $party) => [
            'customer_id' => $party->id, 'customer_name' => $party->name,
            'credit_limit_minor' => $party->credit_limit_minor === null ? null : (string) $party->credit_limit_minor,
            'credit_limit_currency' => $party->credit_limit_currency,
            'balances' => collect($this->ledger->balances($party->id, $companyIds))->map(fn (int $amount, string $currency) => ['currency' => $currency, 'amount_minor' => (string) $amount])->values()->all(),
            ...($detail ? ['entries' => CreditEntry::query()->where('customer_id', $party->id)->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))->orderByDesc('occurred_at')->orderByDesc('id')->limit(200)->get()->map(fn (CreditEntry $e) => [
                'id' => $e->id, 'kind' => $e->kind, 'currency' => $e->currency, 'amount_minor' => (string) $e->amount_minor,
                'occurred_at' => $this->iso($e->occurred_at), 'run_id' => $e->run_id,
            ])->values()->all()] : []),
        ])->values()->all();
    }

    /** @param Collection<int, VanSale> $sales */
    public function creditReviews(Collection $sales): array
    {
        $runs = Run::query()->whereKey($sales->pluck('run_id'))->get()->keyBy('id');
        $vans = $this->names(Van::class, $runs->pluck('van_id'));
        $reps = $this->names(User::class, [...$runs->pluck('rep_id')->all(), ...$sales->pluck('reviewed_by')->all()]);
        $customers = $this->names(Party::class, $sales->pluck('customer_id'));

        return $sales->map(fn (VanSale $sale) => [
            'id' => $sale->id, 'sale_id' => $sale->sale_id, 'run_id' => $sale->run_id,
            'van_name' => $vans[$runs[$sale->run_id]?->van_id] ?? null, 'rep_name' => $reps[$runs[$sale->run_id]?->rep_id] ?? null,
            'customer_id' => $sale->customer_id, 'customer_name' => $customers[$sale->customer_id] ?? null, 'currency' => $sale->currency,
            'total_minor' => (string) $sale->total_minor, 'credit_minor' => (string) $sale->credit_minor,
            'limit_minor' => $sale->limit_minor === null ? null : (string) $sale->limit_minor,
            'balance_before_minor' => $sale->balance_before_minor === null ? null : (string) $sale->balance_before_minor,
            'flags' => array_values($sale->flags ?? []), 'sold_at' => $this->iso($sale->sold_at), 'review_status' => $sale->review_status,
            'reviewed_by_name' => $reps[$sale->reviewed_by] ?? null, 'reviewed_at' => $this->iso($sale->reviewed_at), 'review_note' => $sale->review_note,
        ])->values()->all();
    }

    public function reconciliation(Count $count, bool $detail = true): array
    {
        $van = Van::query()->find($count->van_id);
        $users = $this->names(User::class, [$count->submitted_by, $count->decided_by]);
        $run = Run::query()->find($count->run_id);
        $row = [
            'id' => $count->id, 'run_id' => $count->run_id, 'run_date' => $run?->run_date->toDateString(), 'van_id' => $count->van_id,
            'van_name' => $van?->name, 'rep_name' => $users[$count->submitted_by] ?? null, 'attempt' => (int) $count->attempt, 'status' => $count->status,
            'submitted_at' => $this->iso($count->submitted_at), 'decided_at' => $this->iso($count->decided_at),
            'decided_by_name' => $users[$count->decided_by] ?? null, 'note' => $count->note,
            'stock_variance_lines' => (int) $count->stock_variance_lines, 'cash_variance_base_minor' => (string) $count->cash_variance_base_minor,
            'unload' => $this->unloads->of($count),
            'kind' => $count->kind,
            'flags' => array_values($count->flags ?? []),
        ];

        if ($detail) {
            $lines = CountLine::query()->where('count_id', $count->id)->get();
            $items = $this->names(Item::class, $lines->pluck('item_id'));
            $row['lines'] = $lines->sortBy(fn ($l) => $items[$l->item_id] ?? '')->map(fn (CountLine $l) => [
                'item_id' => $l->item_id, 'item_name' => $items[$l->item_id] ?? null, 'load_qty' => Qty::show((string) $l->load_qty),
                'sold_qty' => Qty::show((string) $l->sold_qty), 'returned_qty' => Qty::show((string) $l->returned_qty), 'returned_claimed_qty' => $l->returned_claimed_qty === null ? null : Qty::show((string) $l->returned_claimed_qty),
                'damaged_qty' => Qty::show((string) $l->damaged_qty), 'variance_qty' => Qty::show((string) $l->variance_qty),
            ])->values()->all();
            $row['tenders'] = CountTender::query()->where('count_id', $count->id)->orderBy('currency')->orderBy('kind')->get()->map(fn (CountTender $t) => [
                'kind' => $t->kind, 'currency' => $t->currency, 'expected_minor' => (string) $t->expected_minor,
                'counted_minor' => (string) $t->counted_minor, 'variance_minor' => (string) $t->variance_minor,
            ])->all();
        }

        return $row;
    }
}
