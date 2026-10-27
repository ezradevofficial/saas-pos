<?php

namespace Modules\FuelStation\Reports;

use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\FuelStation\Http\Present;
use Modules\FuelStation\Models\FuelSale;
use Modules\FuelStation\Models\MeterReset;
use Modules\FuelStation\Models\Reconciliation;
use Modules\FuelStation\Models\Settlement;
use Modules\FuelStation\Models\Shift;
use Modules\FuelStation\Models\ShiftNozzle;
use Modules\FuelStation\Support\Access;
use Modules\FuelStation\Support\Litres;

/**
 * FUEL-34, FUEL-38: the module's reports. Every report is limited to the
 * stations where the reader holds `fuel.report.view` (RBAC-04), covers a
 * period of days (UTC, default the last 30) and keeps money per currency
 * (amounts are minor-unit digit strings, litres decimals with 3 places).
 * Only live sales count (voided ones do not).
 */
class Reports
{
    public const PERMISSION = 'fuel.report.view';

    public function __construct(private readonly Access $access, private readonly Present $present) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} from (start of day) and to (start of the day after) */
    public function period(Request $request): array
    {
        $to = $request->filled('to') ? CarbonImmutable::parse($request->query('to'))->startOfDay() : CarbonImmutable::now()->startOfDay();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->query('from'))->startOfDay() : $to->subDays(29);

        return [$from, $to->addDay()];
    }

    /** FUEL-34: each shift of the period with its litres by the meters, expected, collected and variance, and totals per currency. */
    public function shiftSummary(User $user, Request $request): array
    {
        [$from, $to] = $this->period($request);
        $shifts = $this->limit(Shift::query(), $user, $request)->where('opened_at', '>=', $from)->where('opened_at', '<', $to)->orderByDesc('opened_at')->orderByDesc('id');
        $page = $shifts->paginate($this->perPage($request));
        $settlements = Settlement::query()->whereIn('shift_id', $page->getCollection()->pluck('id'))->get()->groupBy('shift_id');

        $rows = $page->getCollection()->map(function (Shift $s) use ($settlements) {
            $own = $settlements->get($s->id) ?? collect();

            return [
                'shift_id' => $s->id, 'location' => $this->present->location($s->location_id), 'status' => $s->status, 'opened_at' => Present::at($s->opened_at), 'closed_at' => Present::at($s->closed_at),
                'currency' => $own->first()?->currency, 'litres' => Present::total($this->sumLitres($own, 'meter_litres'), true),
                'expected_minor' => (string) $own->sum('expected_minor'), 'collected_minor' => (string) $own->sum('collected_minor'), 'variance_minor' => (string) $own->sum('variance_minor'),
                'attendants' => $own->count(), 'pending' => $own->where('status', Settlement::PENDING)->count(),
            ];
        })->values()->all();

        $totals = $this->limit(Settlement::query(), $user, $request)->join('fuel_shifts', 'fuel_shifts.id', '=', 'fuel_settlements.shift_id')
            ->where('fuel_shifts.opened_at', '>=', $from)->where('fuel_shifts.opened_at', '<', $to)->where('fuel_settlements.status', '<>', Settlement::OPEN)
            ->groupBy('fuel_settlements.currency')->selectRaw('fuel_settlements.currency, sum(meter_litres) as litres, sum(expected_minor) as expected, sum(collected_minor) as collected, sum(variance_minor) as variance')
            ->get()->map(fn ($t) => ['currency' => $t->currency, 'litres' => Present::total($t->litres, true), 'expected_minor' => Present::total($t->expected), 'collected_minor' => Present::total($t->collected), 'variance_minor' => Present::total($t->variance)])->all();

        return ['data' => $rows, 'totals' => $totals, 'meta' => $this->meta($page)];
    }

    /** FUEL-34: per attendant over the period: settlements, expected, collected, variance (money per currency), litres difference and how they were decided. */
    public function attendantVariance(User $user, Request $request): array
    {
        [$from, $to] = $this->period($request);
        $query = $this->limit(Settlement::query(), $user, $request)->where('status', '<>', Settlement::OPEN)->where('created_at', '>=', $from)->where('created_at', '<', $to);

        if ($request->filled('attendant_id')) {
            $query->where('attendant_id', $request->query('attendant_id'));
        }

        $rows = $query->groupBy('attendant_id', 'currency')->orderBy('attendant_id')->selectRaw(
            "attendant_id, currency, count(*) as settlements, sum(expected_minor) as expected, sum(collected_minor) as collected, sum(variance_minor) as variance, sum(litres_variance) as litres_variance,
             sum(case when variance_minor < 0 then variance_minor else 0 end) as shortage, sum(case when status = 'pending_approval' then 1 else 0 end) as pending,
             sum(case when status = 'approved' then 1 else 0 end) as approved, sum(case when status = 'rejected' then 1 else 0 end) as rejected, sum(case when status = 'auto_accepted' then 1 else 0 end) as auto_accepted",
        )->get();

        return ['data' => $rows->map(fn ($r) => [
            'attendant' => $this->present->user($r->attendant_id), 'currency' => $r->currency, 'settlements' => (int) $r->settlements,
            'expected_minor' => Present::total($r->expected), 'collected_minor' => Present::total($r->collected), 'variance_minor' => Present::total($r->variance),
            'shortage_minor' => Present::total($r->shortage), 'litres_variance' => Present::total($r->litres_variance, true),
            'pending' => (int) $r->pending, 'approved' => (int) $r->approved, 'rejected' => (int) $r->rejected, 'auto_accepted' => (int) $r->auto_accepted,
        ])->all()];
    }

    /** FUEL-34: every opening and closing reading of the period, with the resets decided in it. */
    public function nozzleMeterHistory(User $user, Request $request): array
    {
        [$from, $to] = $this->period($request);
        $query = ShiftNozzle::query()->join('fuel_shifts', 'fuel_shifts.id', '=', 'fuel_shift_nozzles.shift_id')->select('fuel_shift_nozzles.*', 'fuel_shifts.opened_at as shift_opened_at', 'fuel_shifts.closed_at as shift_closed_at', 'fuel_shifts.location_id as shift_location_id')
            ->where('fuel_shifts.opened_at', '>=', $from)->where('fuel_shifts.opened_at', '<', $to)->orderByDesc('fuel_shifts.opened_at')->orderBy('fuel_shift_nozzles.nozzle_id');
        $this->access->scopeStations($query, $user, self::PERMISSION, 'fuel_shifts.location_id');

        foreach (['nozzle_id' => 'fuel_shift_nozzles.nozzle_id', 'attendant_id' => 'fuel_shift_nozzles.attendant_id', 'location_id' => 'fuel_shifts.location_id'] as $param => $column) {
            if ($request->filled($param)) {
                $query->where($column, $request->query($param));
            }
        }

        $page = $query->paginate($this->perPage($request));
        $resets = $this->limit(MeterReset::query(), $user, $request)->where('status', MeterReset::APPROVED)->where('decided_at', '>=', $from)->where('decided_at', '<', $to)
            ->when($request->filled('nozzle_id'), fn ($q) => $q->where('nozzle_id', $request->query('nozzle_id')))->orderBy('decided_at')->get();

        return [
            'data' => $page->getCollection()->map(fn ($r) => [
                'shift_id' => $r->shift_id, 'location' => $this->present->location($r->shift_location_id), 'nozzle' => $this->present->nozzleRef($r->nozzle_id), 'attendant' => $this->present->user($r->attendant_id),
                'opened_at' => Present::at(CarbonImmutable::parse($r->shift_opened_at)), 'closed_at' => $r->shift_closed_at === null ? null : Present::at(CarbonImmutable::parse($r->shift_closed_at)),
                'opening_litres' => Litres::show($r->opening_litres), 'closing_litres' => Litres::show($r->closing_litres), 'test_litres' => Litres::show($r->test_litres),
                'reset_litres' => Litres::show($r->reset_litres), 'gap_litres' => Litres::show($r->gap_litres), 'litres' => Litres::show($r->meter_litres),
            ])->values()->all(),
            'resets' => $resets->map(fn (MeterReset $r) => $this->present->meterReset($r))->all(),
            'meta' => $this->meta($page),
        ];
    }

    /** FUEL-34: every reconciliation of the period, and per tank the losses, gains and net. */
    public function tankReconciliation(User $user, Request $request): array
    {
        [$from, $to] = $this->period($request);
        $query = $this->limit(Reconciliation::query(), $user, $request)->where('status', '<>', Reconciliation::BASELINE)->where('created_at', '>=', $from)->where('created_at', '<', $to);

        if ($request->filled('tank_id')) {
            $query->where('tank_id', $request->query('tank_id'));
        }

        $rows = (clone $query)->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($request));
        $totals = (clone $query)->whereIn('status', [Reconciliation::ACCEPTED, Reconciliation::APPROVED])->groupBy('tank_id')->selectRaw(
            'tank_id, count(*) as dips, sum(sales_litres) as sold, sum(deliveries_litres) as received, sum(case when variance_litres < 0 then variance_litres else 0 end) as losses, sum(case when variance_litres > 0 then variance_litres else 0 end) as gains, sum(variance_litres) as net',
        )->get();

        return [
            'data' => $rows->getCollection()->map(fn (Reconciliation $r) => $this->present->reconciliation($r))->values()->all(),
            'totals' => $totals->map(fn ($t) => [
                'tank' => $this->present->tankRef($t->tank_id), 'reconciliations' => (int) $t->dips, 'sold_litres' => Present::total($t->sold, true), 'received_litres' => Present::total($t->received, true),
                'loss_litres' => Present::total($t->losses, true), 'gain_litres' => Present::total($t->gains, true), 'net_litres' => Present::total($t->net, true),
            ])->all(),
            'meta' => $this->meta($rows),
        ];
    }

    /** FUEL-34: litres and value per product, station and currency. */
    public function salesByProduct(User $user, Request $request): array
    {
        [$from, $to] = $this->period($request);
        $rows = $this->limit(FuelSale::query(), $user, $request)->whereNull('voided_at')->where('sold_at', '>=', $from)->where('sold_at', '<', $to)
            ->groupBy('location_id', 'product_id', 'currency')->orderBy('location_id')->orderBy('product_id')
            ->selectRaw('location_id, product_id, currency, count(*) as sales, sum(litres) as litres, sum(total_minor) as total')->get();

        return ['data' => $rows->map(fn ($r) => [
            'location' => $this->present->location($r->location_id), 'product' => $this->present->productRef($r->product_id), 'currency' => $r->currency,
            'sales' => (int) $r->sales, 'litres' => Present::total($r->litres, true), 'total_minor' => Present::total($r->total),
            'average_price_minor' => Present::total((float) $r->litres > 0 ? (float) $r->total / (float) $r->litres : 0),
        ])->all()];
    }

    /** FUEL-38: fleet and credit sales per customer and vehicle, with totals per customer and currency. */
    public function fleetStatement(User $user, Request $request): array
    {
        [$from, $to] = $this->period($request);
        $query = $this->limit(FuelSale::query(), $user, $request)->whereNull('voided_at')->whereNotNull('customer_id')->where('sold_at', '>=', $from)->where('sold_at', '<', $to);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        $totals = (clone $query)->groupBy('customer_id', 'currency')->orderBy('customer_id')->selectRaw('customer_id, currency, count(*) as sales, sum(litres) as litres, sum(total_minor) as total')->get();
        $page = (clone $query)->orderBy('customer_id')->orderBy('vehicle_registration')->orderBy('sold_at')->paginate($this->perPage($request));

        return [
            'data' => $page->getCollection()->map(fn (FuelSale $s) => $this->present->sale($s))->values()->all(),
            'totals' => $totals->map(fn ($t) => ['customer' => $this->present->party($t->customer_id), 'currency' => $t->currency, 'sales' => (int) $t->sales, 'litres' => Present::total($t->litres, true), 'total_minor' => Present::total($t->total)])->all(),
            'meta' => $this->meta($page),
        ];
    }

    /** The query limited to the reader's stations and the request's `location_id`. */
    private function limit(Builder $query, User $user, Request $request): Builder
    {
        $this->access->scopeStations($query, $user, self::PERMISSION);

        return $request->filled('location_id') ? $query->where($query->qualifyColumn('location_id'), $request->query('location_id')) : $query;
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', 25)));
    }

    /** @return array{current_page: int, last_page: int, per_page: int, total: int} */
    private function meta($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }

    private function sumLitres($rows, string $column): string
    {
        return (string) $rows->reduce(fn ($sum, $r) => $sum->plus(Litres::of((string) $r->{$column})), Litres::zero());
    }
}
