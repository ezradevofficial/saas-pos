<?php

namespace Modules\Accounting\Ledger;

use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Period;

/**
 * ACC-08: a company's monthly periods. A fiscal year starts on the first
 * day of the company's `fiscal_year_start_month` and is named by the
 * calendar year it starts in; its twelve periods are created together the
 * first time anything is dated in it (under an advisory lock per company,
 * so two first postings never create them twice).
 */
class Periods
{
    /** The fiscal year (its starting calendar year) that $date falls in. */
    public function fiscalYearOf(Company $company, CarbonImmutable $date): int
    {
        $start = $this->startMonth($company);

        return $date->month >= $start ? $date->year : $date->year - 1;
    }

    /** The first day of fiscal year $year. */
    public function yearStart(Company $company, int $year): CarbonImmutable
    {
        return CarbonImmutable::create($year, $this->startMonth($company), 1)->startOfDay();
    }

    /** The period $date falls in, creating its fiscal year's periods when it has none. */
    public function forDate(Company $company, CarbonImmutable $date): Period
    {
        $date = $date->startOfDay();
        $found = Period::query()->where('company_id', $company->id)
            ->where('starts_on', '<=', $date->toDateString())->where('ends_on', '>=', $date->toDateString())->first();

        return $found ?? $this->createYear($company, $this->fiscalYearOf($company, $date))
            ->first(fn (Period $period) => $period->starts_on <= $date && $period->ends_on >= $date);
    }

    /**
     * The twelve periods of fiscal year $year, created when missing.
     *
     * @return Collection<int, Period>
     */
    public function year(Company $company, int $year): Collection
    {
        $periods = Period::query()->where('company_id', $company->id)->where('fiscal_year', $year)->orderBy('number')->get();

        return $periods->count() === 12 ? $periods : $this->createYear($company, $year);
    }

    /** @return Collection<int, Period> */
    private function createYear(Company $company, int $year): Collection
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $year) {
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['acc_periods:'.$company->id]);
            $start = $this->yearStart($company, $year);

            for ($number = 1; $number <= 12; $number++) {
                $from = $start->addMonthsNoOverflow($number - 1);
                Period::query()->firstOrCreate(
                    ['company_id' => $company->id, 'starts_on' => $from->toDateString()],
                    ['fiscal_year' => $year, 'number' => $number, 'ends_on' => $from->endOfMonth()->toDateString(), 'status' => Period::OPEN],
                );
            }

            return Period::query()->where('company_id', $company->id)->where('fiscal_year', $year)->orderBy('number')->get();
        });
    }

    private function startMonth(Company $company): int
    {
        $month = (int) ($company->fiscal_year_start_month ?: 1);

        return $month >= 1 && $month <= 12 ? $month : 1;
    }
}
