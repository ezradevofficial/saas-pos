<?php

namespace Modules\Accounting\Posting;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Ledger\JournalPoster;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Ledger\LineInput;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\PostingException;
use Modules\Accounting\Models\PostingRule;

/**
 * ACC-07: posts a source document through its company's posting rule.
 *
 * - Idempotent: the journal is keyed by (company, source type, source id)
 *   (a unique index); posting the same source again returns the journal it
 *   already has, even when two posts race.
 * - Configuration, not code: each rule line takes one amount field of the
 *   source to an account on a side (a negative amount to the other side),
 *   with the source's dimensions and, where the line says so, its party.
 * - Never lost: a source that cannot post (no rule, a closed period, an
 *   archived account, a missing rate, unbalanced amounts) is kept as a
 *   posting exception with the reason, and retried (retry()).
 * - Base amounts use the source's own rate when it gives one (CUR-04),
 *   else the core rate on its date; a rounding difference of at most one
 *   minor unit per line goes to the rounding account.
 */
class PostingService
{
    public function __construct(private readonly JournalPoster $poster) {}

    public function post(SourceDocument $source, ?User $by = null): PostingResult
    {
        if ($existing = $this->existing($source)) {
            $this->resolve($source, $existing);

            return new PostingResult($existing, created: false);
        }

        try {
            $journal = DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->journal($source, $by));
        } catch (UniqueConstraintViolationException) {
            // Another post of the same source won the race: answer with its journal.
            $existing = $this->existing($source) ?? throw LedgerError::make('posting_conflict');

            return new PostingResult($existing, created: false);
        } catch (ApiException $e) {
            return new PostingResult(null, created: false, exception: $this->keep($source, $e->errorCode, $e->getMessage()));
        }

        $this->resolve($source, $journal);

        return new PostingResult($journal, created: true);
    }

    /** Try a kept source again (after a rule, an account or a period was fixed). */
    public function retry(PostingException $exception, ?User $by = null): PostingResult
    {
        return $this->post(SourceDocument::fromArray($exception->payload), $by);
    }

    private function journal(SourceDocument $source, ?User $by): Journal
    {
        $company = Company::query()->find($source->companyId) ?? throw LedgerError::make('company_unknown');
        $rule = PostingRule::query()->where('company_id', $company->id)->where('event_type', $source->sourceType)->whereNull('archived_at')->first()
            ?? throw LedgerError::make('posting_rule_missing', ['type' => $source->sourceType]);

        $dimensions = array_intersect_key($source->dimensions, array_flip(['branch_id', 'cost_centre_id', 'department_id', 'project_id']));
        $lines = [];

        foreach ($rule->lines as $line) {
            $amount = $source->amount((string) $line['field']);

            if ($amount->isZero()) {
                continue;
            }

            $lines[] = LineInput::signed(
                (string) $line['account_id'],
                (string) $line['side'],
                $amount,
                $source->fx,
                $source->reference,
                [...$dimensions, 'party_id' => ($line['party'] ?? false) ? ($source->dimensions['party_id'] ?? null) : null],
            );
        }

        if ($lines === []) {
            throw LedgerError::make('journal_empty');
        }

        return $this->poster->record($company, [
            'date' => $this->localDate($company, $source->date),
            'kind' => 'system',
            'source_type' => $source->sourceType,
            'source_id' => $source->sourceId,
            'reference' => $source->reference === null ? null : mb_substr($source->reference, 0, 80),
            'memo' => $source->memo,
            'created_by' => $by?->id,
        ], $lines, $by, rounding: true);
    }

    /** The day the source happened in the company's time zone. */
    private function localDate(Company $company, CarbonImmutable $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at->setTimezone($company->timezone ?: 'UTC')->toDateString());
    }

    private function existing(SourceDocument $source): ?Journal
    {
        return Journal::query()->where('company_id', $source->companyId)
            ->where('source_type', $source->sourceType)->where('source_id', $source->sourceId)->first();
    }

    private function keep(SourceDocument $source, string $code, string $message): PostingException
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($source, $code, $message) {
            $row = PostingException::query()->where('company_id', $source->companyId)
                ->where('source_type', $source->sourceType)->where('source_id', $source->sourceId)->lockForUpdate()->first();

            if ($row === null) {
                return PostingException::query()->create([
                    'company_id' => $source->companyId,
                    'source_type' => $source->sourceType,
                    'source_id' => $source->sourceId,
                    'payload' => $source->toArray(),
                    'error_code' => mb_substr($code, 0, 40),
                    'error_message' => $message,
                    'status' => PostingException::OPEN,
                    'last_attempt_at' => CarbonImmutable::now(),
                ]);
            }

            $row->fill([
                'payload' => $source->toArray(),
                'error_code' => mb_substr($code, 0, 40),
                'error_message' => $message,
                'attempts' => $row->attempts + 1,
                'status' => PostingException::OPEN,
                'last_attempt_at' => CarbonImmutable::now(),
            ])->save();

            return $row;
        });
    }

    private function resolve(SourceDocument $source, Journal $journal): void
    {
        PostingException::query()->where('company_id', $source->companyId)->where('source_type', $source->sourceType)
            ->where('source_id', $source->sourceId)->where('status', PostingException::OPEN)
            ->get()->each(fn (PostingException $row) => $row->fill(['status' => PostingException::RESOLVED, 'journal_id' => $journal->id])->save());
    }
}
