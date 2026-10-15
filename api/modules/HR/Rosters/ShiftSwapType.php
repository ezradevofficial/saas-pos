<?php

namespace Modules\HR\Rosters;

use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Support\Str;
use Modules\HR\Models\Employee;
use Modules\HR\Models\ShiftSwap;

/**
 * WF-01, HR-19: `hr.shift_swap`, after the peer accepted. Scoped at the
 * requester's place; the requester (the employee's user, else whoever
 * asked) never approves it. Default flow: the Branch Managers at the place,
 * escalated to the HR Officers after one business day.
 */
class ShiftSwapType extends DocumentType
{
    public const KEY = 'hr.shift_swap';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'hr.workflow.shift_swap.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::boolean('give_away', 'hr.workflow.fields.give_away'),
            FieldDefinition::reference('employee', 'hr.workflow.fields.employee', 'hr.employee'),
            FieldDefinition::reference('location', 'hr.workflow.fields.location', 'core.location'),
            FieldDefinition::reference('branch', 'hr.workflow.fields.branch', 'core.branch'),
            FieldDefinition::reference('company', 'hr.workflow.fields.company', 'core.company'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        $swap = $this->find($documentId);

        return $swap === null ? [] : [
            'give_away' => $swap->peer_roster_shift_id === null,
            'employee' => $swap->employee_id,
            'location' => $swap->location_id,
            'branch' => $swap->branch_id,
            'company' => $swap->company_id,
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        $swap = $this->find($documentId);

        return $swap === null ? null : new DocumentScope($swap->company_id, $swap->branch_id, $swap->location_id);
    }

    public function viewPermission(): string
    {
        return 'hr.roster.view';
    }

    public function actPermission(): string
    {
        return 'hr.swap.approve';
    }

    public function requesterId(string $documentId): ?string
    {
        $swap = $this->find($documentId);

        return $swap === null ? null : (Employee::query()->whereKey($swap->employee_id)->value('user_id') ?? $swap->requested_by);
    }

    public function documentLink(string $documentId): string
    {
        return '/hr/rosters/swaps/'.rawurlencode($documentId);
    }

    public function actions(): array
    {
        return ['submit', 'approve', 'reject', 'cancel'];
    }

    public function summary(string $documentId): array
    {
        $swap = $this->find($documentId);

        if ($swap === null) {
            return ['number' => null, 'title' => null, 'amount' => null];
        }

        $names = Employee::query()->whereKey([$swap->employee_id, $swap->peer_employee_id])->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return ['number' => null, 'title' => trim(($names[$swap->employee_id]?->displayName() ?? '').' / '.($names[$swap->peer_employee_id]?->displayName() ?? '')), 'amount' => null];
    }

    public function amountLabel(): ?string
    {
        return null;
    }

    public function defaultFlow(?string $country): ?array
    {
        $prefix = 'hr.workflow.shift_swap';

        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __("{$prefix}.start")],
                ['id' => 'approve', 'type' => 'approval', 'name' => __("{$prefix}.approve"),
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:branch_manager'], 'mode' => 'any'],
                    'escalation' => ['after' => ['amount' => 1, 'unit' => 'business_days'], 'to' => ['type' => 'role', 'role' => 'template:hr_officer']]],
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

    private function find(string $documentId): ?ShiftSwap
    {
        return Str::isUuid($documentId) ? ShiftSwap::query()->find($documentId) : null;
    }
}
