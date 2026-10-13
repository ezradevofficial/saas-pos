<?php

namespace Modules\Accounting\Ledger;

use App\Core\Automation\Capabilities\LinksDocuments;
use App\Core\Currency\Money;
use App\Core\Workflow\Definitions\FlowGraph;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Journal;

/**
 * ACC-04: `accounting.journal`, a manual (or recurring) journal waiting to
 * post. Its place is its company; its requester is who drafted it, never
 * its approver (APR-07). The default flow (WF-02), the same in every
 * country: one approval by an Accountant at the company or tenant-wide,
 * escalating to the Owners after two business days; approved posts the
 * journal (SettleJournal), rejected returns it to its author. A flow must
 * pass an approval before an `approved` end.
 */
class JournalType extends DocumentType implements LinksDocuments
{
    public const KEY = 'accounting.journal';

    public const VIEW = 'accounting.journal.view';

    public const APPROVE = 'accounting.journal.approve';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'accounting.journal.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::money('total', 'accounting.journal.fields.total'),
            FieldDefinition::string('memo', 'accounting.journal.fields.memo'),
            FieldDefinition::date('date', 'accounting.journal.fields.date'),
            FieldDefinition::enum('kind', 'accounting.journal.fields.kind', Journal::EDITABLE_KINDS),
            FieldDefinition::reference('company', 'accounting.journal.fields.company', 'core.company'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        $journal = $this->find($documentId);

        if ($journal === null) {
            return [];
        }

        return [
            'total' => Money::ofMinor((string) $journal->total_base_minor, $journal->base_currency),
            'memo' => $journal->memo,
            'date' => $journal->date->toDateString(),
            'kind' => $journal->kind,
            'company' => $journal->company_id,
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        $journal = $this->find($documentId);

        return $journal === null ? null : new DocumentScope($journal->company_id);
    }

    public function requesterId(string $documentId): ?string
    {
        return $this->find($documentId)?->created_by;
    }

    public function documentLink(string $documentId): string
    {
        return '/accounting/journals/'.rawurlencode($documentId);
    }

    public function viewPermission(): string
    {
        return self::VIEW;
    }

    public function actPermission(): string
    {
        return self::APPROVE;
    }

    public function actions(): array
    {
        return ['submit', 'approve', 'reject', 'cancel'];
    }

    public function summary(string $documentId): array
    {
        $journal = $this->find($documentId);

        if ($journal === null) {
            return ['number' => null, 'title' => null, 'amount' => null];
        }

        return [
            'number' => $journal->number,
            'title' => $journal->memo === null ? null : Str::limit($journal->memo, 80),
            'amount' => ['amount_minor' => (string) $journal->total_base_minor, 'currency' => $journal->base_currency],
        ];
    }

    /** An `approved` end must not be reachable without passing an approval (as credit limit changes). */
    public function validateFlow(FlowGraph $flow): array
    {
        $start = $flow->start();

        if ($start === null) {
            return [];
        }

        $problems = [];

        foreach ($flow->reachableWithoutApproval([$start]) as $id) {
            $node = $flow->node($id) ?? [];

            if (($node['type'] ?? null) === 'end' && ($node['outcome'] ?? null) === 'approved') {
                $problems[] = ['code' => 'approval_required', 'message' => __('accounting.journal.validation.approval_required', ['node' => $flow->name($id)]), 'node' => $id];
            }
        }

        return $problems;
    }

    public function defaultFlow(?string $country): ?array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __('accounting.journal.flow.start')],
                ['id' => 'approve', 'type' => 'approval', 'name' => __('accounting.journal.flow.approve'),
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:accountant'], 'mode' => 'any'],
                    'escalation' => ['after' => ['amount' => 2, 'unit' => 'business_days'], 'to' => ['type' => 'role', 'role' => 'template:owner']]],
                ['id' => 'approved', 'type' => 'end', 'outcome' => 'approved', 'name' => __('accounting.journal.flow.approved')],
                ['id' => 'rejected', 'type' => 'end', 'outcome' => 'rejected', 'name' => __('accounting.journal.flow.rejected')],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'approve'],
                ['from' => 'approve', 'to' => 'approved', 'branch' => 'approved'],
                ['from' => 'approve', 'to' => 'rejected', 'branch' => 'rejected'],
            ],
        ];
    }

    private function find(string $documentId): ?Journal
    {
        return Str::isUuid($documentId) ? Journal::query()->whereIn('kind', Journal::EDITABLE_KINDS)->find($documentId) : null;
    }
}
