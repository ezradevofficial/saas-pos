<?php

namespace Modules\Restaurant\Reports;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Restaurant\Billing\Bill;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\OrderLine;
use Modules\Restaurant\Models\Settlement;
use Modules\Restaurant\Support\Places;

/**
 * REST-18: covers, orders, sales and tips per currency, average spend per
 * cover and per order, average table turn time, sales per waiter and voids
 * after send, for a period and a place, at the locations the reader may
 * see (`restaurant.report.view`, RBAC-04). Reads Restaurant's tables only:
 * sales are the settlements of live (not voided) sales.
 */
class Summary
{
    public function __construct(private readonly Places $places) {}

    /**
     * @param  array{from: string, to: string, company_id?: ?string, branch_id?: ?string, location_id?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function run(User $user, array $filters): array
    {
        $from = CarbonImmutable::parse($filters['from'])->startOfDay()->utc();
        $to = CarbonImmutable::parse($filters['to'])->endOfDay()->utc();

        $orders = fn () => $this->place(Order::query(), $user, $filters)->where('status', 'closed')->whereBetween('closed_at', [$from, $to]);
        $settlements = fn () => $this->place(Settlement::query(), $user, $filters)->whereNull('voided_at')->whereBetween('settled_at', [$from, $to]);

        $currencies = [];

        foreach ($orders()->selectRaw('currency, count(*) as orders, coalesce(sum(covers), 0) as covers')->groupBy('currency')->get() as $row) {
            $currencies[$row->currency] = ['orders' => (int) $row->orders, 'covers' => (int) $row->covers, 'sales_minor' => '0', 'tips_minor' => '0'];
        }

        foreach ($settlements()->selectRaw('currency, coalesce(sum(total_minor), 0) as sales, coalesce(sum(tip_minor), 0) as tips')->groupBy('currency')->get() as $row) {
            $currencies[$row->currency] ??= ['orders' => 0, 'covers' => 0, 'sales_minor' => '0', 'tips_minor' => '0'];
            $currencies[$row->currency]['sales_minor'] = (string) $row->sales;
            $currencies[$row->currency]['tips_minor'] = (string) $row->tips;
        }

        foreach ($currencies as $code => $row) {
            $currencies[$code]['avg_per_cover_minor'] = $this->average($row['sales_minor'], $row['covers']);
            $currencies[$code]['avg_per_order_minor'] = $this->average($row['sales_minor'], $row['orders']);
        }

        ksort($currencies);
        $turn = $orders()->where('kind', 'table')->selectRaw('avg(extract(epoch from (closed_at - opened_at)) / 60) as minutes')->value('minutes');

        $waiters = [];

        foreach ($orders()->selectRaw('waiter_id, currency, count(*) as orders, coalesce(sum(covers), 0) as covers')->groupBy('waiter_id', 'currency')->get() as $row) {
            $waiters["{$row->waiter_id}|{$row->currency}"] = ['waiter_id' => $row->waiter_id, 'currency' => $row->currency, 'orders' => (int) $row->orders, 'covers' => (int) $row->covers, 'sales_minor' => '0', 'tips_minor' => '0'];
        }

        $sold = $this->place(Settlement::query()->join('rest_orders', 'rest_orders.id', '=', 'rest_settlements.order_id'), $user, $filters, 'rest_settlements.location_id')
            ->whereNull('rest_settlements.voided_at')->whereBetween('rest_settlements.settled_at', [$from, $to])
            ->selectRaw('rest_orders.waiter_id, rest_settlements.currency, coalesce(sum(rest_settlements.total_minor), 0) as sales, coalesce(sum(rest_settlements.tip_minor), 0) as tips')
            ->groupBy('rest_orders.waiter_id', 'rest_settlements.currency')->get();

        foreach ($sold as $row) {
            $key = "{$row->waiter_id}|{$row->currency}";
            $waiters[$key] ??= ['waiter_id' => $row->waiter_id, 'currency' => $row->currency, 'orders' => 0, 'covers' => 0, 'sales_minor' => '0', 'tips_minor' => '0'];
            $waiters[$key]['sales_minor'] = (string) $row->sales;
            $waiters[$key]['tips_minor'] = (string) $row->tips;
        }

        $names = User::query()->whereKey(array_unique(array_column($waiters, 'waiter_id')))->pluck('name', 'id');
        $waiters = array_values(array_map(fn (array $w) => $w + ['name' => $names[$w['waiter_id']] ?? null], $waiters));
        usort($waiters, fn ($a, $b) => BigInteger::of($b['sales_minor'])->compareTo($a['sales_minor']) ?: strcmp((string) $a['name'], (string) $b['name']));

        [$voids, $reasons] = $this->voids($user, $filters, $from, $to);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currencies' => (object) $currencies,
            'avg_turn_minutes' => $turn === null ? null : (int) round((float) $turn),
            'waiters' => $waiters,
            'voids' => $voids,
            'void_reasons' => $reasons,
        ];
    }

    /** REST-11, REST-18: lines voided after they were sent, by person and currency. */
    private function voids(User $user, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lines = $this->place(OrderLine::query()->join('rest_orders', 'rest_orders.id', '=', 'rest_order_lines.order_id'), $user, $filters, 'rest_order_lines.location_id')
            ->where('rest_order_lines.void_after_send', true)->whereBetween('rest_order_lines.voided_at', [$from, $to])
            ->get(['rest_order_lines.qty', 'rest_order_lines.unit_price_minor', 'rest_order_lines.modifiers', 'rest_order_lines.tax_rate', 'rest_order_lines.tax_inclusive',
                'rest_order_lines.voided_by', 'rest_order_lines.void_reason', 'rest_orders.currency']);
        $by = [];
        $reasons = [];

        foreach ($lines as $line) {
            $value = Bill::line([
                'qty' => (string) $line->qty, 'unit_price_minor' => (string) $line->unit_price_minor, 'modifiers' => json_decode((string) $line->modifiers, true) ?: [],
                'tax_rate' => $line->tax_rate === null ? null : (string) $line->tax_rate, 'tax_inclusive' => (bool) $line->tax_inclusive,
            ])['payable'];
            $key = "{$line->voided_by}|{$line->currency}";
            $by[$key] ??= ['user_id' => $line->voided_by, 'currency' => $line->currency, 'count' => 0, 'value' => BigInteger::zero()];
            $by[$key]['count']++;
            $by[$key]['value'] = $by[$key]['value']->plus($value);
            $reason = trim((string) $line->void_reason) ?: '-';
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }

        $names = User::query()->whereKey(array_unique(array_column($by, 'user_id')))->pluck('name', 'id');
        $voids = array_values(array_map(fn (array $v) => ['user_id' => $v['user_id'], 'name' => $names[$v['user_id']] ?? null, 'currency' => $v['currency'], 'count' => $v['count'], 'value_minor' => (string) $v['value']], $by));
        usort($voids, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp((string) $a['name'], (string) $b['name']));
        arsort($reasons);

        return [$voids, array_map(fn ($reason, $count) => ['reason' => (string) $reason, 'count' => $count], array_keys($reasons), array_values($reasons))];
    }

    private function average(string $total, int $count): ?string
    {
        return $count === 0 ? null : (string) BigInteger::of($total)->multipliedBy(2)->plus($count)->quotient($count * 2);
    }

    /**
     * Limits $query to the filter's place and the locations the reader may see.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function place(Builder $query, User $user, array $filters, string $column = 'location_id'): Builder
    {
        $qualified = str_contains($column, '.') ? $column : $query->qualifyColumn($column);
        $this->places->filterLocations($query, $user, 'restaurant.report.view', $qualified);

        if (! empty($filters['location_id'])) {
            $query->where($qualified, $filters['location_id']);
        }

        if (! empty($filters['branch_id'])) {
            $query->whereIn($qualified, Location::query()->where('branch_id', $filters['branch_id'])->select('id'));
        }

        if (! empty($filters['company_id'])) {
            $query->whereIn($qualified, Location::query()->whereIn('branch_id', Branch::query()->where('company_id', $filters['company_id'])->select('id'))->select('id'));
        }

        return $query;
    }
}
