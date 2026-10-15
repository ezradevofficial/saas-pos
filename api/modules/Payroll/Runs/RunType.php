<?php

namespace Modules\Payroll\Runs;

use App\Core\Identity\Models\User;
use App\Core\Rbac\FieldRules;
use App\Core\Workflow\Definitions\FlowGraph;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Support\Str;
use Modules\Payroll\Models\PayGroup;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Support\PayFields;

/**
 * WF-01, PAY-16: `payroll.run`, a calculated run waiting for approval
 * (maker-checker). Its place is its company; its requester is the
 * preparer, who last calculated it (APR-07: they never approve it, nor
 * does the submitter). Default flow (WF-02): one approval by an Owner at
 * the company, escalating to the Approvers after two business days; an
 * `approved` end is reachable only through an approval. Conditions may
 * route on the net total, the kind, the pay group and the head count.
 * The engine reads runs only through this class (architecture rule 1).
 */
class RunType extends DocumentType
{
    public const KEY = 'payroll.run';

    public const VIEW = 'payroll.run.view';

    public const APPROVE = 'payroll.run.approve';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'payroll.workflow.run.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::money('net_total', 'payroll.workflow.fields.net_total'),
            FieldDefinition::enum('kind', 'payroll.workflow.fields.kind', [Run::REGULAR, Run::CORRECTION]),
            FieldDefinition::string('pay_group', 'payroll.workflow.fields.pay_group'),
            FieldDefinition::number('employees', 'payroll.workflow.fields.employees'),
            FieldDefinition::date('pay_date', 'payroll.workflow.fields.pay_date'),
            FieldDefinition::reference('company', 'payroll.workflow.fields.company', 'core.company'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        $run = $this->find($documentId);

        if ($run === null) {
            return [];
        }

        $base = $run->totals['base'] ?? null;

        return [
            'net_total' => $base === null || $base['currency'] === null ? null : ['amount_minor' => (string) $base['net_minor'], 'currency' => $base['currency']],
            'kind' => $run->kind,
            'pay_group' => PayGroup::query()->whereKey($run->pay_group_id)->value('code'),
            'employees' => (string) ($run->totals['employees'] ?? 0),
            'pay_date' => $run->pay_date->toDateString(),
            'company' => $run->company_id,
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        $run = $this->find($documentId);

        return $run === null ? null : new DocumentScope($run->company_id);
    }

    /** APR-07: the preparer (who calculated the run last), else who created it. */
    public function requesterId(string $documentId): ?string
    {
        $run = $this->find($documentId);

        return $run?->calculated_by ?? $run?->created_by;
    }

    public function documentLink(string $documentId): string
    {
        return '/payroll/runs/'.rawurlencode($documentId);
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
        $run = $this->find($documentId);

        if ($run === null) {
            return ['number' => null, 'title' => null, 'amount' => null];
        }

        $group = PayGroup::query()->find($run->pay_group_id, ['code', 'name']);
        $base = $run->totals['base'] ?? null;

        return [
            'number' => $run->number,
            'title' => trim(($group?->name ?? '').' '.$run->period_start->toDateString().' – '.$run->period_end->toDateString()),
            'amount' => $base === null || $base['currency'] === null ? null : ['amount_minor' => (string) $base['net_minor'], 'currency' => $base['currency']],
        ];
    }

    public function amountLabel(): ?string
    {
        return 'payroll.workflow.fields.net_total';
    }

    /** PAY-23: run totals hidden by the viewer's field rules stay out of the inbox. */
    public function hiddenSummaryFields(User $viewer): array
    {
        $hidden = app(FieldRules::class)->for($viewer, PayFields::RESOURCE)['hidden'];

        return in_array(PayFields::TOTALS, $hidden, true) ? ['amount'] : [];
    }

    /** An `approved` end must not be reachable without passing an approval (maker-checker). */
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
                $problems[] = ['code' => 'approval_required', 'message' => __('payroll.workflow.run.approval_required', ['node' => $flow->name($id)]), 'node' => $id];
            }
        }

        return $problems;
    }

    public function defaultFlow(?string $country): ?array
    {
        $prefix = 'payroll.workflow.run';

        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __("{$prefix}.start")],
                ['id' => 'approve', 'type' => 'approval', 'name' => __("{$prefix}.approve"),
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:owner'], 'mode' => 'any'],
                    'escalation' => ['after' => ['amount' => 2, 'unit' => 'business_days'], 'to' => ['type' => 'role', 'role' => 'template:approver']]],
                ['id' => 'approved', 'type' => 'end', 'outcome' => 'approved', 'name' => __("{$prefix}.approved")],
                ['id' => 'rejected', 'type' => 'end', 'outcome' => 'rejected', 'name' => __("{$prefix}.rejected")],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'approve'],
                ['from' => 'approve', 'to' => 'approved', 'branch' => 'approved'],
                ['from' => 'approve', 'to' => 'rejected', 'branch' => 'rejected'],
            ],
        ];
    }

    private function find(string $documentId): ?Run
    {
        return Str::isUuid($documentId) ? Run::query()->find($documentId) : null;
    }
}
