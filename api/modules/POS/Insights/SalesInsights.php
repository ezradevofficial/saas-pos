<?php

namespace Modules\POS\Insights;

use App\Core\Currency\Converter;
use App\Core\Currency\ExchangeRates;
use App\Core\Currency\Models\CompanyCurrency;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\Sale;

/**
 * TEN-07: completed sales of a period across the companies, branches and
 * locations a user reaches with `pos.sale.view` (RBAC-04).
 *
 * - The period is calendar days in each company's own time zone.
 * - Totals per company are in its base currency, from the base amounts
 *   each sale stored (CUR-04); branches and locations likewise.
 * - The reporting currency is `currency`, else the default (defaultReporting).
 * - The consolidated figure is in the reporting currency: each company's
 *   base total converted once at its rate in force at the end of the
 *   period (or now, if sooner). A company without such a rate is listed
 *   in `missing_rates` and left out of the consolidated total, never
 *   guessed.
 * - Payments are what was tendered, per method type and currency; change
 *   given is listed per currency. Top items rank by quantity sold.
 * - Amounts are minor-unit strings with their currency; quantities are
 *   decimal strings. No floats.
 */
class SalesInsights
{
    public const TOP_ITEMS = 10;

    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly ExchangeRates $rates,
        private readonly Converter $converter,
    ) {}

    /**
     * @param  array{from: string, to: string, company?: string, branch?: string, location?: string, currency?: string}  $filters
     * @return array<string, mixed>
     */
    public function for(User $user, array $filters): array
    {
        $visible = $this->resolver->visibleIds($user, 'pos.sale.view');

        $sales = function () use ($visible, $filters): Builder {
            $query = $visible->applyTo(Sale::query(), Scope::LOCATION)
                ->join('companies', 'companies.id', '=', 'pos_sales.company_id')
                ->where('pos_sales.status', Sale::COMPLETED)
                // The period in each company's own time zone.
                ->whereRaw('pos_sales.sold_at >= (?::date)::timestamp at time zone companies.timezone', [$filters['from']])
                ->whereRaw('pos_sales.sold_at < ((?::date) + 1)::timestamp at time zone companies.timezone', [$filters['to']]);

            foreach (['company' => 'company_id', 'branch' => 'branch_id', 'location' => 'location_id'] as $filter => $column) {
                if (isset($filters[$filter])) {
                    $query->where("pos_sales.{$column}", $filters[$filter]);
                }
            }

            return $query;
        };

        $rows = $sales()
            ->groupBy('pos_sales.company_id', 'pos_sales.branch_id', 'pos_sales.location_id')
            ->get([
                'pos_sales.company_id', 'pos_sales.branch_id', 'pos_sales.location_id',
                DB::raw('count(*) as sales_count'),
                DB::raw('sum(pos_sales.base_total_minor)::text as base_total'),
                DB::raw('sum(pos_sales.base_tax_minor)::text as base_tax'),
            ])
            ->toBase();

        $companies = Company::query()->whereKey($rows->pluck('company_id')->unique()->all())->get()->keyBy('id');
        $branches = Branch::query()->whereKey($rows->pluck('branch_id')->unique()->all())->pluck('name', 'id');
        $locations = Location::query()->whereKey($rows->pluck('location_id')->unique()->all())->pluck('name', 'id');

        $reporting = $filters['currency'] ?? $this->defaultReporting($companies->all());
        $out = [];
        $consolidated = BigInteger::zero();
        $count = 0;
        $missing = [];

        foreach ($rows->groupBy('company_id') as $companyId => $companyRows) {
            /** @var Company $company */
            $company = $companies[$companyId];
            $base = $company->base_currency;
            $companyCount = (int) $companyRows->sum('sales_count');
            $total = $this->sum($companyRows->pluck('base_total'));
            $tax = $this->sum($companyRows->pluck('base_tax'));
            $count += $companyCount;

            $converted = null;

            if ($reporting !== null) {
                $converted = $this->toReporting(Money::ofMinor((string) $total, $base), $reporting, $company, $filters['to']);

                if ($converted === null) {
                    $missing[] = $company->id;
                } else {
                    $consolidated = $consolidated->plus(BigInteger::of($converted['total']->minor()));
                }
            }

            $out[] = [
                'company' => ['id' => $company->id, 'name' => $company->name],
                'base_currency' => $base,
                'timezone' => $company->timezone,
                'sales_count' => $companyCount,
                'total' => Money::ofMinor((string) $total, $base),
                'tax' => Money::ofMinor((string) $tax, $base),
                'average_ticket' => $this->average($total, $companyCount, $base),
                'reporting' => $converted === null ? null : [
                    'total' => $converted['total'],
                    'average_ticket' => $this->average(BigInteger::of($converted['total']->minor()), $companyCount, $reporting),
                    'rate' => $converted['rate'],
                ],
                'branches' => $companyRows->groupBy('branch_id')->map(fn ($branchRows, $branchId) => [
                    'branch' => ['id' => $branchId, 'name' => $branches[$branchId] ?? null],
                    'sales_count' => (int) $branchRows->sum('sales_count'),
                    'total' => Money::ofMinor((string) $this->sum($branchRows->pluck('base_total')), $base),
                    'locations' => $branchRows->map(fn ($row) => [
                        'location' => ['id' => $row->location_id, 'name' => $locations[$row->location_id] ?? null],
                        'sales_count' => (int) $row->sales_count,
                        'total' => Money::ofMinor((string) $row->base_total, $base),
                    ])->sortBy(fn ($row) => $row['location']['name'])->values()->all(),
                ])->sortBy(fn ($row) => $row['branch']['name'])->values()->all(),
            ];
        }

        usort($out, fn (array $a, array $b) => strcmp((string) $a['company']['name'], (string) $b['company']['name']));

        return [
            'period' => ['from' => $filters['from'], 'to' => $filters['to']],
            'reporting_currency' => $reporting,
            'sales_count' => $count,
            'consolidated' => $reporting === null ? null : [
                'total' => Money::ofMinor((string) $consolidated, $reporting),
                // Only over the companies whose total could be converted.
                'average_ticket' => $this->average($consolidated, $count - $this->countOf($out, $missing), $reporting),
                'complete' => $missing === [],
            ],
            'missing_rates' => $missing,
            'companies' => $out,
            'payments' => $this->payments($sales),
            'change' => $this->change($sales),
            'top_items' => $this->topItems($sales),
        ];
    }

    /** @param iterable<string|null> $values */
    private function sum(iterable $values): BigInteger
    {
        $sum = BigInteger::zero();

        foreach ($values as $value) {
            $sum = $sum->plus(BigInteger::of((string) ($value ?? '0')));
        }

        return $sum;
    }

    private function average(BigInteger $total, int $count, string $currency): ?Money
    {
        if ($count <= 0) {
            return null;
        }

        return Money::ofMinor((string) BigDecimal::of($total)->dividedBy($count, 0, RoundingMode::HalfUp), $currency);
    }

    /** @param list<string> $missing */
    private function countOf(array $companies, array $missing): int
    {
        return array_sum(array_map(fn (array $row) => in_array($row['company']['id'], $missing, true) ? $row['sales_count'] : 0, $companies));
    }

    /**
     * Owner ruling 2026-10-09: the first reporting currency configured
     * (CUR-02, position 1) of the companies shown, in name order; else the
     * common base currency when every company shares one; else none (the
     * reader picks).
     */
    private function defaultReporting(array $companies): ?string
    {
        $firsts = CompanyCurrency::query()->whereIn('company_id', array_keys($companies))->where('position', 1)->pluck('code', 'company_id');
        $named = $companies;
        uasort($named, fn (Company $a, Company $b) => strcmp((string) $a->name, (string) $b->name));

        foreach ($named as $id => $company) {
            if (isset($firsts[$id])) {
                return $firsts[$id];
            }
        }

        $bases = array_values(array_unique(array_map(fn (Company $company) => $company->base_currency, $companies)));

        return count($bases) === 1 ? $bases[0] : null;
    }

    /** @return array{total: Money, rate: ?array<string, mixed>}|null */
    private function toReporting(Money $total, string $reporting, Company $company, string $to): ?array
    {
        if ($total->currency() === $reporting) {
            return ['total' => $total, 'rate' => null];
        }

        $end = CarbonImmutable::parse($to, $company->timezone)->addDay()->startOfDay()->utc();
        $at = $end->isFuture() ? CarbonImmutable::now() : $end->subSecond();

        try {
            $rate = $this->rates->stored($company, $total->currency(), $reporting, $at);
        } catch (RateUnavailable) {
            return null;
        }

        return [
            'total' => $this->converter->convert($total, $reporting, $rate),
            'rate' => ['base' => $rate->base, 'quote' => $rate->quote, 'rate' => $rate->value(), 'kind' => $rate->kind, 'effective_at' => $rate->effectiveAt->toIso8601String()],
        ];
    }

    /** @return list<array{method_type: string, currency: string, count: int, amount: Money}> */
    private function payments(\Closure $sales): array
    {
        return $sales()
            ->join('pos_sale_payments', 'pos_sale_payments.sale_id', '=', 'pos_sales.id')
            ->groupBy('pos_sale_payments.method_type', 'pos_sale_payments.currency')
            ->orderBy('pos_sale_payments.method_type')->orderBy('pos_sale_payments.currency')
            ->get([
                'pos_sale_payments.method_type', 'pos_sale_payments.currency',
                DB::raw('count(*) as payments_count'),
                DB::raw('sum(pos_sale_payments.amount_minor)::text as amount'),
            ])
            ->map(fn ($row) => [
                'method_type' => $row->method_type,
                'currency' => $row->currency,
                'count' => (int) $row->payments_count,
                'amount' => Money::ofMinor((string) $row->amount, $row->currency),
            ])->values()->all();
    }

    /** @return list<Money> change given, per currency */
    private function change(\Closure $sales): array
    {
        return $sales()
            ->where('pos_sales.change_minor', '>', 0)
            ->groupBy('pos_sales.change_currency')
            ->orderBy('pos_sales.change_currency')
            ->get(['pos_sales.change_currency', DB::raw('sum(pos_sales.change_minor)::text as amount')])
            ->map(fn ($row) => Money::ofMinor((string) $row->amount, $row->change_currency))
            ->values()->all();
    }

    /** @return list<array{item: array{id: string, name: string}, qty: string, sales_count: int, totals: list<Money>}> */
    private function topItems(\Closure $sales): array
    {
        $rows = $sales()
            ->join('pos_sale_lines', 'pos_sale_lines.sale_id', '=', 'pos_sales.id')
            ->groupBy('pos_sale_lines.item_id', 'pos_sales.currency')
            ->get([
                'pos_sale_lines.item_id', 'pos_sales.currency',
                DB::raw('max(pos_sale_lines.item_name) as item_name'),
                DB::raw('sum(pos_sale_lines.qty)::text as qty'),
                DB::raw('sum(pos_sale_lines.total_minor)::text as total'),
                DB::raw('count(distinct pos_sales.id) as sales_count'),
            ])
            ->toBase();

        return $rows->groupBy('item_id')
            ->map(function ($itemRows, $itemId) {
                $qty = BigDecimal::zero();

                foreach ($itemRows as $row) {
                    $qty = $qty->plus(BigDecimal::of($row->qty));
                }

                return [
                    'item' => ['id' => $itemId, 'name' => $itemRows->first()->item_name],
                    'qty' => (string) $qty->strippedOfTrailingZeros(),
                    'sales_count' => (int) $itemRows->sum('sales_count'),
                    'totals' => $itemRows->sortBy('currency')->map(fn ($row) => Money::ofMinor((string) $row->total, $row->currency))->values()->all(),
                    'sort' => $qty,
                ];
            })
            ->sort(fn (array $a, array $b) => $b['sort']->compareTo($a['sort']) ?: strcmp((string) $a['item']['name'], (string) $b['item']['name']))
            ->take(self::TOP_ITEMS)
            ->map(fn (array $row) => array_diff_key($row, ['sort' => true]))
            ->values()->all();
    }
}
