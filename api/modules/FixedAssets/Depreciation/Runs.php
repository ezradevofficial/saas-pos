<?php

namespace Modules\FixedAssets\Depreciation;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\FixedAssets\Categories\Categories;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\DepreciationLine;
use Modules\FixedAssets\Models\DepreciationRun;
use Modules\FixedAssets\Models\Disposal;
use Modules\FixedAssets\Models\Posting;
use Modules\FixedAssets\Models\Revaluation;
use Modules\FixedAssets\Postings\Postings;
use Modules\FixedAssets\Support\ClosedBooks;
use Modules\FixedAssets\Support\FaError;

/**
 * FA-12, FA-13: monthly depreciation runs of a company.
 *
 * - preview(): each active asset's charge for the month, nothing saved.
 * - post(): under a lock per company, the run, its lines, each asset's
 *   accumulated depreciation and depreciated-to date, and one ledger
 *   source per branch and cost centre (FA-22). In order (`run_out_of_order`),
 *   once per month (`run_exists`, also a unique index), never in a closed
 *   period (`period_closed`).
 * - reverse(): the latest run, while nothing later depends on it.
 */
class Runs
{
    public function __construct(
        private readonly Spans $spans,
        private readonly ClosedBooks $books,
        private readonly Postings $postings,
    ) {}

    /**
     * @return array{period: string, period_end: string, convention: string, currency: string, total: int,
     *     lines: list<array{asset: Asset, from: string, to: string, amount: int, units: ?string, accumulated_after: int, book_value_after: int}>,
     *     closed_through: ?string, next_period: ?string}
     */
    public function preview(Company $company, string $month): array
    {
        [$period, $end] = self::month($month);
        $convention = (string) Categories::settings($company->id)->convention;
        $lines = [];

        $assets = Asset::query()->where('company_id', $company->id)->where('status', Asset::ACTIVE)->whereNull('archived_at')
            ->where('in_service_on', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('depreciated_to')->orWhere('depreciated_to', '<', $end->toDateString()))
            ->orderBy('number')->orderBy('id')->get();

        foreach ($assets as $asset) {
            $span = $this->spans->next($asset, $end, $convention);

            if ($span === null || $span['charge']->amount <= 0) {
                continue;
            }

            $amount = $span['charge']->amount;
            $lines[] = [
                'asset' => $asset,
                'from' => $span['from']->toDateString(),
                'to' => $span['to']->toDateString(),
                'amount' => $amount,
                'units' => $span['charge']->units,
                'accumulated_after' => (int) $asset->accumulated_minor + $amount,
                'book_value_after' => $asset->bookValue() - $amount,
            ];
        }

        return [
            'period' => $period->toDateString(),
            'period_end' => $end->toDateString(),
            'convention' => $convention,
            'currency' => (string) $company->base_currency,
            'total' => array_sum(array_column($lines, 'amount')),
            'lines' => $lines,
            'closed_through' => $this->books->closedThrough($company->id),
            'next_period' => $this->nextPeriod($company->id),
        ];
    }

    public function post(Company $company, string $month, User $by): DepreciationRun
    {
        [$period, $end] = self::month($month);

        try {
            return FaError::transaction(function () use ($company, $month, $period, $end, $by) {
                DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['fa_runs:'.$company->id]);

                if (DepreciationRun::query()->where('company_id', $company->id)->where('period', $period->toDateString())->where('status', 'posted')->exists()) {
                    throw FaError::make('run_exists', ['month' => $month], 'period', 409);
                }

                $next = $this->nextPeriod($company->id);

                if ($next !== null && $next !== $period->toDateString()) {
                    throw FaError::make('run_out_of_order', ['month' => substr($next, 0, 7)], 'period');
                }

                $this->books->assertOpen($company->id, $end, 'period');
                $preview = $this->preview($company, $month);

                $run = DepreciationRun::query()->create([
                    'company_id' => $company->id,
                    'period' => $period->toDateString(),
                    'period_end' => $end->toDateString(),
                    'status' => 'posted',
                    'convention' => $preview['convention'],
                    'base_currency' => (string) $company->base_currency,
                    'total_minor' => $preview['total'],
                    'assets_count' => count($preview['lines']),
                    'posted_by' => $by->id,
                    'posted_at' => CarbonImmutable::now(),
                ]);
                $groups = [];

                foreach ($preview['lines'] as $line) {
                    /** @var Asset $asset */
                    $asset = Asset::query()->whereKey($line['asset']->id)->lockForUpdate()->firstOrFail();
                    DepreciationLine::query()->create([
                        'run_id' => $run->id,
                        'asset_id' => $asset->id,
                        'category_id' => $asset->category_id,
                        'branch_id' => $asset->branch_id,
                        'cost_centre_id' => $asset->cost_centre_id,
                        'from_date' => $line['from'],
                        'to_date' => $line['to'],
                        'amount_minor' => $line['amount'],
                        'units' => $line['units'],
                        'previous_depreciated_to' => $asset->depreciated_to?->toDateString(),
                        'accumulated_after_minor' => $line['accumulated_after'],
                        'book_value_after_minor' => $line['book_value_after'],
                    ]);
                    $asset->fill(['accumulated_minor' => $line['accumulated_after'], 'depreciated_to' => $line['to']])->save();
                    $key = $asset->branch_id.'|'.($asset->cost_centre_id ?? '');
                    $groups[$key]['branch_id'] = $asset->branch_id;
                    $groups[$key]['cost_centre_id'] = $asset->cost_centre_id;
                    $groups[$key]['amounts']["depreciation.{$asset->category_id}"] = ($groups[$key]['amounts']["depreciation.{$asset->category_id}"] ?? 0) + $line['amount'];
                    $groups[$key]['amounts']["accumulated.{$asset->category_id}"] = ($groups[$key]['amounts']["accumulated.{$asset->category_id}"] ?? 0) + $line['amount'];
                }

                ksort($groups);

                foreach ($groups as $group) {
                    $this->postings->raise(Postings::DEPRECIATION, 'run', $run->id, $company->id, $group['branch_id'], $group['cost_centre_id'], null,
                        $end, (string) $company->base_currency, $group['amounts'], __('fixed_assets.runs.reference', ['month' => $month]), null);
                }

                return $run->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent post of the same month won.
            throw FaError::make('run_exists', ['month' => $month], 'period', 409);
        }
    }

    public function reverse(DepreciationRun $run, User $by, string $reason): DepreciationRun
    {
        return FaError::transaction(function () use ($run, $by, $reason) {
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['fa_runs:'.$run->company_id]);
            $run = DepreciationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $latest = DepreciationRun::query()->where('company_id', $run->company_id)->where('status', 'posted')->orderByDesc('period')->first();

            if ($run->status !== 'posted' || $latest?->id !== $run->id) {
                throw FaError::make('run_not_latest', status: 409);
            }

            $this->books->assertOpen($run->company_id, $run->period_end, 'period');
            $lines = DepreciationLine::query()->where('run_id', $run->id)->get();
            $assetIds = $lines->pluck('asset_id')->all();
            $since = $run->period->toDateString();

            $later = Revaluation::query()->whereIn('asset_id', $assetIds)->where('revalued_on', '>=', $since)->exists()
                || Disposal::query()->whereIn('asset_id', $assetIds)->where('status', 'posted')->where('disposal_date', '>=', $since)->exists();

            if ($later) {
                throw FaError::make('run_has_later_entries', status: 409);
            }

            foreach ($lines as $line) {
                $asset = Asset::query()->whereKey($line->asset_id)->lockForUpdate()->firstOrFail();
                $asset->fill([
                    'accumulated_minor' => (int) $asset->accumulated_minor - (int) $line->amount_minor,
                    'depreciated_to' => $line->previous_depreciated_to?->toDateString(),
                ])->save();
            }

            foreach (Posting::query()->where('document_type', 'run')->where('document_id', $run->id)->where('reverse', false)->orderBy('created_at')->get() as $posting) {
                $this->postings->raise(Postings::DEPRECIATION, 'run', $run->id, $run->company_id, $posting->branch_id, $posting->cost_centre_id, null,
                    $run->period_end, (string) $posting->currency, $posting->amounts, __('fixed_assets.runs.reversal_reference', ['month' => $run->period->format('Y-m')]),
                    null, reverse: true, reverses: $posting->id);
            }

            $run->fill(['status' => 'reversed', 'reversed_by' => $by->id, 'reversed_at' => CarbonImmutable::now(), 'reverse_reason' => $reason])->save();

            return $run->refresh();
        });
    }

    /** The month after the company's latest posted run (Y-m-d), or null before its first run. */
    public function nextPeriod(string $companyId): ?string
    {
        $last = DepreciationRun::query()->where('company_id', $companyId)->where('status', 'posted')->max('period');

        return $last === null ? null : CarbonImmutable::parse($last)->addMonthNoOverflow()->startOfMonth()->toDateString();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the first and last day of `Y-m` */
    public static function month(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);

        return [$start, $start->endOfMonth()->startOfDay()];
    }
}
