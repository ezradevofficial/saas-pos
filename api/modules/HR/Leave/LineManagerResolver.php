<?php

namespace Modules\HR\Leave;

use App\Core\Approvals\Resolvers\ApprovalSubject;
use App\Core\Approvals\Resolvers\ApproverResolver;
use App\Core\Approvals\Resolvers\ApproverResolvers;
use App\Core\Workflow\DocumentTypes\DocumentType;
use Illuminate\Support\Str;
use Modules\HR\Models\Employee;

/**
 * APR-02 `line_manager`, registered by HR: the linked user of the
 * `reports_to` employee of the employee the document names (its reference
 * field to `hr.employee`). When the employee has no line manager, or the
 * manager has no user, the node's escalation target is asked at once
 * (HR-14: the HR Officers by default); with no target either, the step
 * falls back to the next level's managers (APR-07).
 */
class LineManagerResolver implements ApproverResolver
{
    public const KEY = 'line_manager';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'hr.workflow.approver_types.line_manager';
    }

    public function params(): array
    {
        return [];
    }

    public function validate(array $approver, DocumentType $type): array
    {
        foreach ($type->fields() as $field) {
            if ($field->type === 'reference' && $field->reference === 'hr.employee') {
                return [];
            }
        }

        return [__('hr.workflow.approver_types.line_manager_needs_employee')];
    }

    public function resolve(array $approver, ApprovalSubject $subject): array
    {
        $employeeId = null;

        foreach ($subject->type->fields() as $field) {
            if ($field->type === 'reference' && $field->reference === 'hr.employee') {
                $employeeId = $subject->values()[$field->name] ?? null;

                break;
            }
        }

        if (! is_string($employeeId) || ! Str::isUuid($employeeId)) {
            return [];
        }

        $managerId = Employee::query()->whereKey($employeeId)->value('reports_to_id');
        $userId = $managerId === null ? null : Employee::query()->whereKey($managerId)->where('status', Employee::ACTIVE)->value('user_id');

        if ($userId !== null) {
            return [(string) $userId];
        }

        $to = $subject->node['escalation']['to'] ?? null;
        $resolver = is_array($to) && is_string($to['type'] ?? null) && $to['type'] !== self::KEY ? app(ApproverResolvers::class)->find($to['type']) : null;

        return $resolver === null ? [] : $resolver->resolve($to, $subject);
    }

    public function describe(array $approver): string
    {
        return __($this->label());
    }
}
