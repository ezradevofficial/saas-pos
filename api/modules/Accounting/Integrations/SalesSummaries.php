<?php

namespace Modules\Accounting\Integrations;

use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\QueuedSource;
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
 */
class SalesSummaries
{
    private const NAMESPACE = 'b9c0e3f4-7a51-4c3e-9b0a-4d2f6a1e8c77';

    public function __construct(private readonly PostingService $posting) {}

    /** @return int the summaries posted */
    public function run(CarbonImmutable $now): int
    {
        $posted = 0;
        $companies = QueuedSource::query()->whereNull('summarised_at')->distinct()->pluck('company_id');

        foreach (Company::query()->whereKey($companies)->get() as $company) {
            $today = $now->setTimezone($company->timezone ?: 'UTC')->toDateString();
            $groups = QueuedSource::query()->where('company_id', $company->id)->whereNull('summarised_at')->where('local_date', '<', $today)
                ->orderBy('local_date')->orderBy('source_id')->get()
                ->groupBy(fn (QueuedSource $row) => $row->location_id.'|'.$row->local_date->toDateString().'|'.$row->currency);

            foreach ($groups as $rows) {
                $posted += $this->summarise($company, $rows->all()) ? 1 : 0;
            }
        }

        return $posted;
    }

    /** @param list<QueuedSource> $rows one location's sales of one day in one currency */
    private function summarise(Company $company, array $rows): bool
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
        ));

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($rows, $id, $result) {
            QueuedSource::query()->whereKey(array_map(fn (QueuedSource $row) => $row->id, $rows))->whereNull('summarised_at')->update([
                'summary_source_id' => $id,
                'journal_id' => $result->journal?->id,
                'summarised_at' => $result->posted() ? now() : null,
                'updated_at' => now(),
            ]);
        });

        return $result->posted() && $result->created;
    }
}
