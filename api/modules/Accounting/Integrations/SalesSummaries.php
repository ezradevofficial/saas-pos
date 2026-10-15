<?php

namespace Modules\Accounting\Integrations;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Models\PostingException;
use Modules\Accounting\Models\QueuedSource;
use Modules\Accounting\Posting\PostingResult;
use Modules\Accounting\Posting\PostingService;
use Modules\Accounting\Posting\SourceDocument;
use Ramsey\Uuid\Uuid;

/**
 * ACC-26: the daily summary journal of a location's POS sales, for
 * companies that post that way. Once a day has ended in the company's
 * time zone, its waiting sales (per location and currency) are summed
 * field by field into one `pos.sale_summary` source, posted with the sale
 * rule, dated that day.
 *
 * The summary's id is derived from the location, the day and the sales it
 * sums, so running again posts nothing twice, and a sale that arrives
 * after its day's summary (a till offline) goes into a new summary of its
 * own for that day. A summary that cannot post stays a posting exception
 * (its sales wait); once it posts (here or by a retry) its sales are
 * marked with its journal.
 *
 * Review #2: never twice. A company's summaries are made under its own
 * advisory lock (runs and retries alike). A new summary that covers sales
 * of an earlier failed one supersedes that exception (status
 * `superseded`, never retried). A retry rebuilds the summary from its own
 * sales and is refused (`summary_superseded`) once any of them is in
 * another summary.
 */
class SalesSummaries
{
    private const NAMESPACE = 'b9c0e3f4-7a51-4c3e-9b0a-4d2f6a1e8c77';

    public function __construct(private readonly PostingService $posting) {}

    /** Stage 7B performance review: hours a group whose summary failed waits before the next automatic try, doubling per attempt up to a day. */
    private const BACKOFF_HOURS = [1, 2, 4, 8, 16, 24];

    /** @return int the summaries posted */
    public function run(CarbonImmutable $now): int
    {
        $posted = 0;
        $companies = QueuedSource::query()->whereNull('summarised_at')->distinct()->pluck('company_id');

        foreach (Company::query()->whereKey($companies)->get() as $company) {
            $today = $now->setTimezone($company->timezone ?: 'UTC')->toDateString();
            // Stage 7B performance review: the groups first (one light query), then one group's sales at a time.
            $groups = QueuedSource::query()->where('company_id', $company->id)->whereNull('summarised_at')->where('local_date', '<', $today)
                ->groupBy('location_id', 'local_date', 'currency')->orderBy('local_date')->orderBy('location_id')->orderBy('currency')
                ->toBase()->get(['location_id', 'local_date', 'currency']);

            foreach ($groups as $group) {
                $posted += $this->locked($company->id, function () use ($company, $group, $now) {
                    $rows = QueuedSource::query()->where('company_id', $company->id)->whereNull('summarised_at')
                        ->where('location_id', $group->location_id)->where('local_date', $group->local_date)->where('currency', $group->currency)
                        ->orderBy('source_id')->get();

                    if ($rows->isEmpty() || $this->backingOff($company, $rows->all(), $now)) {
                        return 0;
                    }

                    $result = $this->summarise($company, $rows->all());

                    return $result->posted() && $result->created ? 1 : 0;
                });
            }
        }

        return $posted;
    }

    /**
     * Stage 7B performance review: a group whose summary already failed
     * (every sale in it carries that summary, still an open exception) is
     * not tried again on every run: it waits 1, 2, 4, 8, 16 then 24 hours
     * after its last attempt. A sale added to the group makes a new summary
     * at once, and a retry from the exceptions screen is never held back.
     *
     * @param  list<QueuedSource>  $rows
     */
    private function backingOff(Company $company, array $rows, CarbonImmutable $now): bool
    {
        $summaries = array_values(array_unique(array_map(fn (QueuedSource $row) => (string) $row->summary_source_id, $rows)));

        if (count($summaries) !== 1 || $summaries[0] === '') {
            return false;
        }

        $exception = PostingException::query()->where('company_id', $company->id)->where('source_type', 'pos.sale_summary')
            ->where('source_id', $summaries[0])->where('status', PostingException::OPEN)->first();

        if ($exception === null) {
            return false;
        }

        $hours = self::BACKOFF_HOURS[min(max((int) $exception->attempts, 1), count(self::BACKOFF_HOURS)) - 1];

        return CarbonImmutable::instance($exception->last_attempt_at)->addHours($hours)->greaterThan($now);
    }

    /** Review #2: retry a failed summary from its own sales, refused once it is superseded. */
    public function retry(PostingException $exception, ?User $by = null): PostingResult
    {
        $company = Company::query()->findOrFail($exception->company_id);

        return $this->locked($company->id, function () use ($exception, $company, $by) {
            $exception = PostingException::query()->whereKey($exception->id)->firstOrFail();
            $rows = QueuedSource::query()->where('company_id', $company->id)->where('summary_source_id', $exception->source_id)->orderBy('source_id')->get();

            if ($exception->status !== PostingException::OPEN || $rows->isEmpty() || $rows->contains(fn (QueuedSource $row) => $row->summarised_at !== null)) {
                throw LedgerError::make('summary_superseded');
            }

            return $this->summarise($company, $rows->all(), $by);
        });
    }

    /** @param list<QueuedSource> $rows one location's sales of one day in one currency */
    private function summarise(Company $company, array $rows, ?User $by = null): PostingResult
    {
        $first = $rows[0];
        $date = $first->local_date->toDateString();
        $ids = array_map(fn (QueuedSource $row) => $row->source_id, $rows);
        $amounts = [];

        foreach ($rows as $row) {
            foreach ((array) ($row->payload['amounts'] ?? []) as $field => $minor) {
                $amounts[$field] = (string) BigInteger::of($amounts[$field] ?? '0')->plus((string) $minor);
            }
        }

        ksort($amounts);
        $id = Uuid::uuid5(self::NAMESPACE, implode('|', [$company->id, $first->location_id, $date, $first->currency, hash('sha256', implode(',', $ids))]))->toString();
        // Earlier summaries of some of these sales that failed: this one replaces them.
        $earlier = array_values(array_unique(array_filter(array_map(fn (QueuedSource $row) => $row->summary_source_id, $rows), fn ($old) => $old !== null && $old !== $id)));

        $result = $this->posting->post(new SourceDocument(
            companyId: $company->id,
            sourceType: 'pos.sale_summary',
            sourceId: $id,
            date: CarbonImmutable::parse("{$date} 12:00:00", $company->timezone ?: 'UTC'),
            currency: $first->currency,
            amounts: array_filter($amounts, fn (string $minor) => $minor !== '0'),
            dimensions: array_filter(['branch_id' => $first->payload['dimensions']['branch_id'] ?? null]),
            reference: $date,
            memo: __('accounting.integrations.summary_memo', ['count' => count($rows), 'date' => $date]),
            link: ['kind' => 'pos.sales', 'id' => $first->location_id, 'date' => $date],
        ), $by);

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $rows, $id, $result, $earlier) {
            QueuedSource::query()->whereKey(array_map(fn (QueuedSource $row) => $row->id, $rows))->whereNull('summarised_at')->update([
                'summary_source_id' => $id,
                'journal_id' => $result->journal?->id,
                'summarised_at' => $result->posted() ? now() : null,
                'updated_at' => now(),
            ]);

            if ($earlier !== []) {
                PostingException::query()->where('company_id', $company->id)->where('source_type', 'pos.sale_summary')
                    ->whereIn('source_id', $earlier)->where('status', PostingException::OPEN)
                    ->update(['status' => PostingException::SUPERSEDED, 'updated_at' => now()]);
            }
        });

        return $result;
    }

    /**
     * Run $fn under the company's summary lock (a transaction-scoped advisory lock).
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function locked(string $companyId, callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($companyId, $fn) {
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['acc_sales_summaries:'.$companyId]);

            return $fn();
        });
    }
}
