<?php

namespace Modules\Accounting\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Http\Requests\ListPostingExceptionsRequest;
use Modules\Accounting\Http\Requests\ListPostingRulesRequest;
use Modules\Accounting\Http\Requests\PostingRuleActionRequest;
use Modules\Accounting\Http\Requests\RetryPostingExceptionRequest;
use Modules\Accounting\Http\Requests\SeedPostingRulesRequest;
use Modules\Accounting\Http\Requests\StorePostingRuleRequest;
use Modules\Accounting\Http\Resources\JournalResource;
use Modules\Accounting\Http\Resources\RecordResource;
use Modules\Accounting\Integrations\SalesSummaries;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\PostingException;
use Modules\Accounting\Models\PostingRule;
use Modules\Accounting\Posting\PostingDefaults;
use Modules\Accounting\Posting\PostingService;

/** ACC-07: posting rules (configuration, not code) and the sources that could not post yet. */
class PostingRuleController
{
    public function index(ListPostingRulesRequest $request, Company $company): AnonymousResourceCollection
    {
        $status = (string) $request->validated('status', 'active');
        $query = PostingRule::query()->where('company_id', $company->id)->orderBy('event_type')->orderByDesc('created_at');

        if ($status !== 'all') {
            $status === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        }

        return RecordResource::collection($query->get())->additional(['meta' => [
            'event_types' => config('accounting.event_types'),
            // ACC-24: types that post with another type's rule while they have none of their own.
            'fallbacks' => array_map(fn (array $f) => ['event_type' => $f[0], 'reversed' => (bool) $f[1]], config('accounting.rule_fallbacks')),
        ]]);
    }

    /** A new rule for an event type replaces (archives) the active one, in one transaction. */
    public function store(StorePostingRuleRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();
        $this->assertLines($data['lines']);
        $this->assertPostable($company, array_values(array_filter([...array_column($data['lines'], 'account_id'), ...array_merge(...array_map(fn (array $l) => array_values($l['accounts'] ?? []), $data['lines']))])));

        try {
            $rule = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $data, $request) {
                PostingRule::query()->where('company_id', $company->id)->where('event_type', $data['event_type'])->whereNull('archived_at')
                    ->lockForUpdate()->get()->each->archive();

                return PostingRule::query()->create([
                    'company_id' => $company->id,
                    'event_type' => $data['event_type'],
                    'description' => $data['description'] ?? null,
                    'lines' => array_map(fn (array $line) => array_filter([
                        'field' => $line['field'], 'side' => $line['side'], 'account_id' => $line['account_id'] ?? null, 'party' => (bool) ($line['party'] ?? false),
                        'by' => $line['by'] ?? null, 'accounts' => ($line['accounts'] ?? null) ?: null,
                    ], fn ($value) => $value !== null), array_values($data['lines'])),
                    'created_by' => $request->user()->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Two rules for the same event type saved at once: the other one won.
            throw LedgerError::make('posting_rule_conflict', status: 409);
        }

        return RecordResource::make($rule)->response()->setStatusCode(201);
    }

    /** ACC-24: add the template's default rules (and accounts) the company is missing. */
    public function defaults(SeedPostingRulesRequest $request, Company $company, PostingDefaults $defaults): JsonResponse
    {
        return response()->json(['data' => $defaults->seed($company, $request->user())]);
    }

    public function archive(PostingRuleActionRequest $request, PostingRule $accPostingRule): RecordResource
    {
        $accPostingRule->archive();

        return RecordResource::make($accPostingRule);
    }

    public function exceptions(ListPostingExceptionsRequest $request, Company $company): AnonymousResourceCollection
    {
        $status = (string) $request->validated('status', 'open');
        $query = PostingException::query()->where('company_id', $company->id)->orderByDesc('last_attempt_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return RecordResource::collection($query->limit(500)->get());
    }

    public function retry(RetryPostingExceptionRequest $request, PostingException $accPostingException, PostingService $posting, SalesSummaries $summaries): JsonResponse
    {
        // Review #2: a daily summary is rebuilt from its own sales (refused once superseded); other sources from their payload.
        $result = match (true) {
            $accPostingException->source_type === 'pos.sale_summary' => $summaries->retry($accPostingException, $request->user()),
            $accPostingException->status === PostingException::SUPERSEDED => throw LedgerError::make('summary_superseded'),
            default => $posting->retry($accPostingException, $request->user()),
        };

        return $result->posted()
            ? response()->json(['data' => ['status' => 'posted', 'journal' => JournalResource::make($result->journal)]])
            : response()->json(['data' => ['status' => 'open', 'exception' => RecordResource::make($result->exception)]], 422);
    }

    /**
     * A line names an account unless it takes `prefix.*` fields (its map may name them all);
     * map keys are payment method, tax code or category ids, `type:{method type}` or `none`.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertLines(array $lines): void
    {
        foreach (array_values($lines) as $index => $line) {
            if (($line['account_id'] ?? null) === null && ! str_ends_with((string) $line['field'], '.*')) {
                throw LedgerError::make('posting_account_missing', ['field' => $line['field']], "lines.{$index}.account_id");
            }

            foreach (array_keys($line['accounts'] ?? []) as $key) {
                if (preg_match('/^[A-Za-z0-9_\-]{1,40}(:[a-z_]{1,20})?\z/', (string) $key) !== 1) {
                    throw LedgerError::make('posting_account_missing', ['field' => $line['field']], "lines.{$index}.accounts");
                }
            }
        }
    }

    /** @param list<string> $ids */
    private function assertPostable(Company $company, array $ids): void
    {
        foreach (Account::query()->where('company_id', $company->id)->whereKey($ids)->get() as $account) {
            if (! $account->postable || $account->isArchived()) {
                throw LedgerError::make('account_not_postable', ['code' => $account->code], 'lines');
            }
        }
    }
}
