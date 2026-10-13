<?php

namespace Modules\Accounting\Ledger;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Journal;

/**
 * ACC-04: manual journals through their flow.
 *
 * draft → (submit) pending → flow approved: approved, then posted (or
 * left approved with `post_error` when posting is refused, e.g. a period
 * closed meanwhile; post again once fixed) | flow rejected: rejected
 * (editable, resubmitted) | cancelled (a draft or rejected journal, or a
 * pending one whose flow is cancelled). Manual lines never post to
 * control accounts (the subledgers own them).
 */
class ManualJournals
{
    public function __construct(
        private readonly JournalPoster $poster,
        private readonly LineParser $parser,
        private readonly WorkflowEngine $engine,
        private readonly Auditor $auditor,
    ) {}

    /** @param array{date: string, memo?: ?string, reference?: ?string, lines: list<array<string, mixed>>} $data */
    public function create(Company $company, array $data, User $by, string $kind = 'manual', ?string $recurringId = null): Journal
    {
        return $this->poster->draft($company, [
            'date' => CarbonImmutable::parse($data['date']),
            'kind' => $kind,
            'memo' => $data['memo'] ?? null,
            'reference' => $data['reference'] ?? null,
            'created_by' => $by->id,
            'recurring_journal_id' => $recurringId,
        ], $this->parser->parse($company, $data['lines']), allowControl: false);
    }

    /** @param array{date?: string, memo?: ?string, reference?: ?string, lines: list<array<string, mixed>>} $data */
    public function update(Journal $journal, array $data): Journal
    {
        $company = Company::query()->findOrFail($journal->company_id);
        $header = array_intersect_key($data, array_flip(['memo', 'reference']));

        if (isset($data['date'])) {
            $header['date'] = CarbonImmutable::parse($data['date']);
        }

        return $this->poster->revise($journal, $header, $this->parser->parse($company, $data['lines']));
    }

    public function submit(Journal $journal, User $by): Journal
    {
        return $this->transaction(function () use ($journal, $by) {
            $journal = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();

            if (! $journal->isEditable()) {
                throw LedgerError::make('journal_not_editable');
            }

            $journal->fill(['status' => Journal::PENDING, 'post_error' => null, 'decided_by' => null, 'decided_at' => null])->save();
            $this->engine->start(JournalType::KEY, $journal->id, $by);

            return $journal->refresh();
        });
    }

    /** WF-10: the journal's flow ended; approved posts it. Runs in the journal's tenant. */
    public function completed(string $journalId, string $outcome, ?string $userId): void
    {
        $approved = $this->transaction(function () use ($journalId, $outcome, $userId) {
            $journal = Journal::query()->whereKey($journalId)->lockForUpdate()->first();

            if ($journal === null || $journal->status !== Journal::PENDING) {
                return null;
            }

            $journal->fill([
                'status' => $outcome === 'approved' ? Journal::APPROVED : Journal::REJECTED,
                'decided_by' => $userId,
                'decided_at' => CarbonImmutable::now(),
            ])->save();

            return $outcome === 'approved' ? $journal : null;
        });

        if ($approved !== null) {
            $this->postApproved($approved, $userId === null ? null : User::query()->find($userId));
        }
    }

    /** An approved journal posts; a refusal is kept on it (post_error) for someone to fix and post again. */
    public function postApproved(Journal $journal, ?User $by): Journal
    {
        if ($journal->status !== Journal::APPROVED) {
            throw LedgerError::make('journal_not_approved');
        }

        try {
            return $this->poster->post($journal, $by);
        } catch (ApiException $e) {
            $journal->fill(['post_error' => mb_substr($e->errorCode, 0, 40)])->save();

            throw $e;
        }
    }

    /** WF-11: the flow was cancelled; the journal goes back to draft. */
    public function flowCancelled(string $journalId): void
    {
        $this->transaction(function () use ($journalId) {
            $journal = Journal::query()->whereKey($journalId)->lockForUpdate()->first();

            if ($journal !== null && $journal->status === Journal::PENDING) {
                $journal->fill(['status' => Journal::DRAFT])->save();
            }
        });
    }

    public function cancel(Journal $journal, User $by, string $reason): Journal
    {
        return $this->transaction(function () use ($journal, $by, $reason) {
            $journal = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();

            if ($journal->status === Journal::PENDING && ($flow = $this->engine->current(JournalType::KEY, $journal->id)) !== null && $flow->status === 'running') {
                $this->engine->cancel($flow, $by, $reason);
            } elseif (! in_array($journal->status, [Journal::DRAFT, Journal::REJECTED, Journal::APPROVED], true)) {
                throw LedgerError::make('journal_not_editable');
            }

            $journal->fill(['status' => Journal::CANCELLED])->save();
            $this->auditor->record('accounting.journal.cancel', $journal, null, ['reason' => $reason]);

            return $journal;
        });
    }

    private function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
