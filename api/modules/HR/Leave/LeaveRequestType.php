<?php

namespace Modules\HR\Leave;

use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Support\Str;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;

/**
 * WF-01, HR-14: `hr.leave_request`. Scoped at the employee's place; the
 * requester (never an approver, APR-07) is the employee's linked user,
 * else whoever asked. Default flow (WF-02): the line manager (APR-02
 * `line_manager`), escalated to the HR Officers after two business days,
 * and to them at once when there is no line manager. Conditions may route
 * on the type, days, paid, department and place. The engine reads requests
 * only through this class (architecture rule 1).
 */
class LeaveRequestType extends DocumentType
{
    public const KEY = 'hr.leave_request';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'hr.workflow.leave_request.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::string('leave_type', 'hr.workflow.fields.leave_type'),
            FieldDefinition::number('days', 'hr.workflow.fields.days'),
            FieldDefinition::boolean('paid', 'hr.workflow.fields.paid'),
            FieldDefinition::date('start_date', 'hr.workflow.fields.start_date'),
            FieldDefinition::reference('employee', 'hr.workflow.fields.employee', 'hr.employee'),
            FieldDefinition::reference('department', 'hr.workflow.fields.department', 'core.department'),
            FieldDefinition::reference('location', 'hr.workflow.fields.location', 'core.location'),
            FieldDefinition::reference('branch', 'hr.workflow.fields.branch', 'core.branch'),
            FieldDefinition::reference('company', 'hr.workflow.fields.company', 'core.company'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        $request = $this->find($documentId);

        if ($request === null) {
            return [];
        }

        $type = LeaveType::query()->find($request->leave_type_id, ['code', 'paid']);

        return [
            'leave_type' => $type?->code,
            'days' => (string) $request->days,
            'paid' => $type?->paid,
            'start_date' => $request->start_date->toDateString(),
            'employee' => $request->employee_id,
            'department' => Employee::query()->whereKey($request->employee_id)->value('department_id'),
            'location' => $request->location_id,
            'branch' => $request->branch_id,
            'company' => $request->company_id,
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        $request = $this->find($documentId);

        return $request === null ? null : new DocumentScope($request->company_id, $request->branch_id, $request->location_id);
    }

    public function viewPermission(): string
    {
        return 'hr.leave.view';
    }

    public function actPermission(): string
    {
        return 'hr.leave.approve';
    }

    public function requesterId(string $documentId): ?string
    {
        $request = $this->find($documentId);

        return $request === null ? null : (Employee::query()->whereKey($request->employee_id)->value('user_id') ?? $request->requested_by);
    }

    /** The employee sees their own request and its flow. */
    public function sees(User $user, string $documentId): bool
    {
        return true;
    }

    public function documentLink(string $documentId): string
    {
        return '/hr/leave/requests/'.rawurlencode($documentId);
    }

    public function actions(): array
    {
        return ['submit', 'approve', 'reject', 'cancel'];
    }

    /** APR-04: the number and the employee's name; leave carries no amount. */
    public function summary(string $documentId): array
    {
        $request = $this->find($documentId);

        if ($request === null) {
            return ['number' => null, 'title' => null, 'amount' => null];
        }

        $employee = Employee::query()->find($request->employee_id, ['first_name', 'last_name']);

        return ['number' => $request->number, 'title' => $employee?->displayName(), 'amount' => null];
    }

    public function amountLabel(): ?string
    {
        return null;
    }

    public function defaultFlow(?string $country): ?array
    {
        $prefix = 'hr.workflow.leave_request';

        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __("{$prefix}.start")],
                ['id' => 'approve', 'type' => 'approval', 'name' => __("{$prefix}.approve"),
                    'approval' => ['approver' => ['type' => LineManagerResolver::KEY], 'mode' => 'any'],
                    'escalation' => ['after' => ['amount' => 2, 'unit' => 'business_days'], 'to' => ['type' => 'role', 'role' => 'template:hr_officer']]],
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

    private function find(string $documentId): ?LeaveRequest
    {
        return Str::isUuid($documentId) ? LeaveRequest::query()->find($documentId) : null;
    }
}
