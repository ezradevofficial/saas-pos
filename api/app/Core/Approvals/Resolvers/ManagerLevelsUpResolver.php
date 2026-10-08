<?php

namespace App\Core\Approvals\Resolvers;

use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * APR-02 `manager_levels_up` (`levels` 1..5): the managers (see
 * ApproverDirectory) assigned exactly at the place `levels` above the
 * document's own (location → branch → company → tenant). Beyond the
 * tenant there is nobody: the step falls back per its escalation (APR-07).
 */
class ManagerLevelsUpResolver implements ApproverResolver
{
    public const MAX_LEVELS = 5;

    public function __construct(private readonly ApproverDirectory $directory) {}

    public function key(): string
    {
        return 'manager_levels_up';
    }

    public function label(): string
    {
        return 'approvals.approver_types.manager_levels_up';
    }

    public function params(): array
    {
        return [['name' => 'levels', 'type' => 'integer', 'min' => 1, 'max' => self::MAX_LEVELS, 'required' => true]];
    }

    public function validate(array $approver, DocumentType $type): array
    {
        $levels = $approver['levels'] ?? null;

        return is_int($levels) && $levels >= 1 && $levels <= self::MAX_LEVELS
            ? []
            : [__('approvals.validation.levels', ['max' => self::MAX_LEVELS])];
    }

    public function resolve(array $approver, ApprovalSubject $subject): array
    {
        $scope = $subject->ancestor((int) ($approver['levels'] ?? 1));

        return $scope === null ? [] : $this->directory->managersAt($scope);
    }

    public function describe(array $approver): string
    {
        return __('approvals.approver_types.manager_levels_up_n', ['levels' => (int) ($approver['levels'] ?? 1)]);
    }
}
