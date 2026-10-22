<?php

namespace App\Core\Platform\Reports;

use App\Core\Billing\Invoicing\Invoicing;
use App\Core\Billing\Models\OperatorAccount;
use App\Core\Billing\Models\OperatorAccountEvent;
use App\Core\Billing\Models\OperatorInvoice;
use App\Core\Billing\Operator\Accounts;
use App\Core\Billing\Operator\Operator;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ADM-06: revenue and usage reports for platform staff, from the operator
 * tenant's own data, read in its context: the accounts (MRR now), the
 * account events (trials started and converted, churn) and the paid
 * operator invoices (revenue). Every amount is net of tax: MRR and the
 * MRR lost to churn are the accounts' tax-inclusive monthly amounts less
 * the tax at their latest period invoice's rate (Invoicing::inclusiveTax). Usage totals come from a
 * narrow security-definer aggregate (`app_platform_usage_totals`, totals
 * only, ADR 002). No customer tenant's tables are read.
 *
 * Amounts are minor units per currency; KES and USD are never added up.
 * Dates are UTC days, `to` included.
 */
class PlatformReports
{
    public function __construct(
        private readonly Operator $operator,
        private readonly TenantContext $tenants,
    ) {}

    /** @return array<string, mixed> */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'mrr' => $this->mrr(),
            'trials' => $this->trials($from, $to),
            'churn' => $this->churn($from, $to),
            'revenue' => $this->revenue($from, $to),
            'usage' => $this->usage($to),
        ];
    }

    /**
     * MRR now: paying accounts' monthly amounts net of tax, per currency,
     * by country, plan and module. A module's share is its part of the
     * account's latest period invoice (net of tax), spread over the
     * invoice's months.
     *
     * @return array{accounts: int, by_currency: list<array<string, mixed>>, by_country: list<array<string, mixed>>, by_plan: list<array<string, mixed>>, by_module: list<array<string, mixed>>}
     */
    public function mrr(): array
    {
        return $this->inOperator(fn () => $this->mrrRows(), ['accounts' => 0, 'by_currency' => [], 'by_country' => [], 'by_plan' => [], 'by_module' => []]);
    }

    private function mrrRows(): array
    {
        $accounts = OperatorAccount::query()->whereIn('status', Accounts::PAYING)->get();
        $invoices = $this->periodInvoices($accounts->pluck('customer_tenant_id')->all());
        $net = fn (OperatorAccount $a) => self::netOfTax((int) $a->mrr_minor, $invoices[$a->customer_tenant_id] ?? null);
        $sum = fn (Collection $rows, callable $key) => $rows->groupBy(fn (OperatorAccount $a) => $key($a).'|'.$a->currency)
            ->map(fn (Collection $group, string $k) => [...self::split($k), 'accounts' => $group->count(), 'amount_minor' => (int) $group->sum($net)])
            ->sortKeys()->values()->all();

        $byModule = [];

        foreach ($accounts as $account) {
            $invoice = $invoices[$account->customer_tenant_id] ?? null;

            // Review L5: the account's MRR shared out by its plan lines (overage left out), so
            // MRR by module adds up to MRR: the annual discount and coupons fall proportionally.
            foreach (self::shareOut($net($account), $invoice === null ? [] : self::netLines($invoice, withOverage: false), $account->bundle ?? 'custom') as $key => $amount) {
                $k = "{$key}|{$account->currency}";
                $byModule[$k] = ($byModule[$k] ?? 0) + $amount;
            }
        }

        ksort($byModule);

        return [
            'accounts' => $accounts->count(),
            'by_currency' => $sum($accounts, fn () => 'all'),
            'by_country' => $sum($accounts, fn (OperatorAccount $a) => $a->country),
            'by_plan' => $sum($accounts, fn (OperatorAccount $a) => $a->bundle ?? 'custom'),
            'by_module' => array_map(fn (string $k, int $amount) => [...self::split($k), 'amount_minor' => $amount], array_keys($byModule), array_values($byModule)),
        ];
    }

    /** @return array{started: int, converted: int, conversion_percent: ?string, by_country: list<array<string, mixed>>} */
    public function trials(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->inOperator(function () use ($from, $to) {
            $events = $this->events([OperatorAccountEvent::TRIAL_STARTED, OperatorAccountEvent::CONVERTED], $from, $to);
            $started = $events->where('kind', OperatorAccountEvent::TRIAL_STARTED);
            $converted = $events->where('kind', OperatorAccountEvent::CONVERTED);
            $countries = $events->pluck('country')->unique()->sort()->values();

            return [
                'started' => $started->count(),
                'converted' => $converted->count(),
                'conversion_percent' => self::percent($converted->count(), $started->count()),
                'by_country' => $countries->map(fn (string $c) => [
                    'country' => $c,
                    'started' => $started->where('country', $c)->count(),
                    'converted' => $converted->where('country', $c)->count(),
                ])->all(),
            ];
        }, ['started' => 0, 'converted' => 0, 'conversion_percent' => null, 'by_country' => []]);
    }

    /**
     * Churn: paying accounts that became cancelled or suspended in the
     * range, with the MRR they paid (net of tax); the rate is over the accounts paying
     * at the start (paying now, less those that started paying in the
     * range, plus those that left).
     *
     * @return array{churned: int, rate_percent: ?string, mrr_lost: list<array<string, mixed>>}
     */
    public function churn(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->inOperator(function () use ($from, $to) {
            $churned = $this->events([OperatorAccountEvent::CHURNED], $from, $to);
            $joined = $this->events([OperatorAccountEvent::CONVERTED, OperatorAccountEvent::SUBSCRIBED, OperatorAccountEvent::REACTIVATED], $from, $to)->count();
            $payingNow = OperatorAccount::query()->whereIn('status', Accounts::PAYING)->count();
            $atStart = max(0, $payingNow - $joined + $churned->count());

            return [
                'churned' => $churned->count(),
                'rate_percent' => self::percent($churned->count(), $atStart),
                'mrr_lost' => $churned->groupBy('currency')->map(fn (Collection $g, string $currency) => ['currency' => $currency, 'accounts' => $g->count(),
                    'amount_minor' => (int) $g->sum(fn (OperatorAccountEvent $e) => self::netOfTax((int) $e->mrr_minor, $this->periodInvoice($e->customer_tenant_id, $e->occurred_at)))])->sortKeys()->values()->all(),
            ];
        }, ['churned' => 0, 'rate_percent' => null, 'mrr_lost' => []]);
    }

    /**
     * Revenue: operator invoices paid in the range, net of tax, per
     * currency, by country (the selling company's), module and plan.
     *
     * @return array{invoices: int, by_currency: list<array<string, mixed>>, by_country: list<array<string, mixed>>, by_module: list<array<string, mixed>>, by_plan: list<array<string, mixed>>}
     */
    public function revenue(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->inOperator(function () use ($from, $to) {
            $invoices = OperatorInvoice::query()->where('status', 'paid')
                ->where('paid_at', '>=', $from->startOfDay())->where('paid_at', '<', $to->addDay()->startOfDay())
                ->with('company:id,country')->orderBy('paid_at')->get();
            $add = function (array &$bucket, string $key, string $currency, int $amount) {
                $k = "{$key}|{$currency}";
                $bucket[$k] = ($bucket[$k] ?? 0) + $amount;
            };
            [$byCurrency, $byCountry, $byModule, $byPlan] = [[], [], [], []];

            foreach ($invoices as $invoice) {
                $net = (int) $invoice->total_minor - (int) $invoice->tax_minor;
                $add($byCurrency, 'all', $invoice->currency, $net);
                $add($byCountry, (string) ($invoice->company?->country ?? '??'), $invoice->currency, $net);
                $plan = collect($invoice->lines)->firstWhere('type', 'bundle')['key'] ?? 'custom';
                $add($byPlan, (string) $plan, $invoice->currency, $net);

                foreach (self::netLines($invoice) as [$key, $lineNet]) {
                    $add($byModule, $key, $invoice->currency, $lineNet);
                }
            }

            $rows = function (array $bucket) {
                ksort($bucket);

                return array_map(fn (string $k, int $amount) => [...self::split($k), 'amount_minor' => $amount], array_keys($bucket), array_values($bucket));
            };

            return [
                'invoices' => $invoices->count(),
                'by_currency' => $rows($byCurrency),
                'by_country' => $rows($byCountry),
                'by_module' => $rows($byModule),
                'by_plan' => $rows($byPlan),
            ];
        }, ['invoices' => 0, 'by_currency' => [], 'by_country' => [], 'by_module' => [], 'by_plan' => []]);
    }

    /** @return list<array{unit: string, tenants: int, total: int}> usage on the latest metered day on or before $on */
    public function usage(CarbonImmutable $on): array
    {
        $rows = DB::connection(TenantContext::CONNECTION)->select('select unit, tenants, total from public.app_platform_usage_totals(?::date)', [$on->toDateString()]);

        return array_map(fn ($r) => ['unit' => (string) $r->unit, 'tenants' => (int) $r->tenants, 'total' => (int) $r->total], $rows);
    }

    /**
     * Each account's MRR net of tax, as the MRR report counts it, for the
     * platform console (ADM-01): customer tenant id => minor units.
     *
     * @param  iterable<OperatorAccount>  $accounts
     * @return array<string, int>
     */
    public function netMrr(iterable $accounts): array
    {
        $accounts = collect($accounts);

        if ($accounts->isEmpty()) {
            return [];
        }

        $invoices = $this->inOperator(fn () => $this->periodInvoices($accounts->pluck('customer_tenant_id')->all()), []);

        return $accounts->mapWithKeys(fn (OperatorAccount $a) => [
            $a->customer_tenant_id => self::netOfTax((int) $a->mrr_minor, $invoices[$a->customer_tenant_id] ?? null),
        ])->all();
    }

    /**
     * The accounts' latest period invoices (first or renewal, not void), in one query.
     *
     * @param  list<string>  $customerTenantIds
     * @return array<string, OperatorInvoice>
     */
    private function periodInvoices(array $customerTenantIds): array
    {
        if ($customerTenantIds === []) {
            return [];
        }

        return OperatorInvoice::query()->selectRaw('distinct on (customer_tenant_id) *')
            ->whereIn('customer_tenant_id', $customerTenantIds)->whereIn('kind', ['first', 'renewal'])->where('status', '<>', 'void')
            ->orderBy('customer_tenant_id')->orderByDesc('period_start')->get()->keyBy('customer_tenant_id')->all();
    }

    /** The account's latest period invoice (first or renewal, not void), issued by $before when given. */
    private function periodInvoice(string $customerTenantId, ?\DateTimeInterface $before = null): ?OperatorInvoice
    {
        return OperatorInvoice::query()->where('customer_tenant_id', $customerTenantId)->whereIn('kind', ['first', 'renewal'])
            ->where('status', '<>', 'void')->when($before !== null, fn ($q) => $q->where('issued_at', '<=', $before))
            ->orderByDesc('period_start')->first();
    }

    /**
     * A tax-inclusive monthly amount less its tax at $invoice's rate, split
     * as invoices split it (Invoicing::inclusiveTax, half up); unchanged
     * without an invoice or with no rate yet (CP-02: never guessed).
     */
    public static function netOfTax(int $gross, ?OperatorInvoice $invoice): int
    {
        $rate = $invoice?->tax_rate;

        return $rate === null ? $gross : $gross - Invoicing::inclusiveTax($gross, (string) $rate);
    }

    /** @return Collection<int, OperatorAccountEvent> */
    private function events(array $kinds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return OperatorAccountEvent::query()->whereIn('kind', $kinds)
            ->where('occurred_at', '>=', $from->startOfDay())->where('occurred_at', '<', $to->addDay()->startOfDay())->get();
    }

    /**
     * The invoice's lines that sell something (modules, bundles, overage), net of their discount and tax.
     *
     * @return list<array{0: string, 1: int, 2: int}> [key, net minor units, months]
     */
    private static function netLines(OperatorInvoice $invoice, bool $withOverage = true): array
    {
        $lines = [];
        $types = $withOverage ? ['module', 'bundle', 'overage'] : ['module', 'bundle'];

        foreach ((array) $invoice->lines as $line) {
            if (! in_array($line['type'] ?? null, $types, true) || ! isset($line['net_minor'])) {
                continue;
            }

            $lines[] = [(string) $line['key'], (int) $line['net_minor'] - (int) ($line['tax_minor'] ?? 0), (int) ($line['months'] ?? 1)];
        }

        return $lines;
    }

    /**
     * $total minor units shared out in proportion to the lines' net
     * amounts, the remainder to the largest line (integers only); all to
     * $fallback without lines.
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $lines
     * @return array<string, int>
     */
    public static function shareOut(int $total, array $lines, string $fallback): array
    {
        $weights = [];

        foreach ($lines as [$key, $net]) {
            $weights[$key] = ($weights[$key] ?? 0) + max(0, $net);
        }

        $sum = array_sum($weights);

        if ($sum === 0) {
            return $total === 0 ? [] : [$fallback => $total];
        }

        $shares = array_map(fn (int $weight) => intdiv($total * $weight, $sum), $weights);
        arsort($weights);
        $shares[array_key_first($weights)] += $total - array_sum($shares);

        return $shares;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  T  $empty
     * @return T
     */
    private function inOperator(callable $fn, mixed $empty): mixed
    {
        return $this->operator->id() === null ? $empty : $this->operator->run($fn);
    }

    /** @return array{key: string, currency: string} */
    private static function split(string $key): array
    {
        [$k, $currency] = explode('|', $key, 2);

        return ['key' => $k, 'currency' => $currency];
    }

    private static function percent(int $part, int $whole): ?string
    {
        if ($whole === 0) {
            return null;
        }

        // Tenths of a percent, rounded half up, in integers.
        $tenths = intdiv(2 * $part * 1000 + $whole, 2 * $whole);

        return intdiv($tenths, 10).'.'.($tenths % 10);
    }
}
