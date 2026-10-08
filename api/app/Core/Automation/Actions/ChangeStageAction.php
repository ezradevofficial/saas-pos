<?php

namespace App\Core\Automation\Actions;

use App\Core\Automation\Runtime\FlowStages;
use App\Core\Http\ApiException;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\Runtime\WorkflowEngine;

/**
 * AUTO-03 "change stage", through the workflow engine and its rules
 * (entry and exit conditions, stage roles, WF-04, WF-08):
 *
 *   {"type": "change_stage", "mode": "move"}                          complete the current stage
 *   {"type": "change_stage", "mode": "move", "stage": "review"}       complete that stage
 *   {"type": "change_stage", "mode": "return", "stage": "draft", "reason": "Missing quote"}
 *
 * The move is made as the rule's last editor; a move the engine refuses
 * (blocked, not allowed, no running workflow) fails the run with the
 * engine's reason. At save the type must have a flow with a `stage` node
 * (for the rule's company or every company), and a named stage must be one
 * of them (FlowStages): approval nodes are decided by approvers.
 */
class ChangeStageAction implements AutomationAction
{
    public const KEY = 'change_stage';

    private const STAGE = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly FlowStages $stages,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function needsDocument(): bool
    {
        return true;
    }

    public function validate(array $action, RuleContext $rule): array
    {
        $mode = $action['mode'] ?? null;
        $stage = $action['stage'] ?? null;
        $problems = [];

        if (! in_array($mode, ['move', 'return'], true)) {
            return [__('automation.validation.stage_mode')];
        }

        if (($mode === 'return' || $stage !== null) && (! is_string($stage) || preg_match(self::STAGE, $stage) !== 1)) {
            $problems[] = __('automation.validation.stage_id');
        } elseif (($movable = $this->stages->movable($rule->type->key(), $rule->companyId)) === []) {
            // Only `stage` nodes move this way; approvals are decided by approvers.
            $problems[] = __('automation.validation.stage_unavailable');
        } elseif (is_string($stage) && ! in_array($stage, $movable, true)) {
            $problems[] = __('automation.validation.stage_unknown', ['stage' => $stage]);
        }

        if ($mode === 'return' && (! is_string($action['reason'] ?? null) || trim($action['reason']) === '' || mb_strlen($action['reason']) > 500)) {
            $problems[] = __('automation.validation.stage_reason');
        }

        if (array_diff(array_keys($action), ['type', 'mode', 'stage', 'reason']) !== []) {
            $problems[] = __('automation.validation.action_extra');
        }

        return $problems;
    }

    public function requiredPermissions(array $action, DocumentType $type): array
    {
        return [$type->actPermission()];
    }

    public function describe(array $action, AutomationContext $context): string
    {
        if (($action['mode'] ?? null) === 'return') {
            return __('automation.actions.change_stage.describe_return', ['stage' => (string) ($action['stage'] ?? ''), 'reason' => (string) ($action['reason'] ?? '')]);
        }

        return isset($action['stage'])
            ? __('automation.actions.change_stage.describe_move', ['stage' => (string) $action['stage']])
            : __('automation.actions.change_stage.describe_move_current');
    }

    public function run(array $action, AutomationContext $context): array
    {
        $documentId = $context->requireDocument();
        $workflow = $this->engine->current($context->type->key(), $documentId);

        if ($workflow === null || ! $workflow->isRunning()) {
            throw new ActionFailed(__('automation.errors.no_workflow'));
        }

        $actor = $context->requireActor();
        $stage = is_string($action['stage'] ?? null) ? $action['stage'] : null;

        try {
            $workflow = ($action['mode'] ?? 'move') === 'return'
                ? $this->engine->returnTo($workflow, $actor, (string) $stage, (string) $action['reason'])
                : $this->engine->move($workflow, $actor, $stage);
        } catch (ApiException $e) {
            throw new ActionFailed($e->getMessage(), 0, $e);
        }

        return ['mode' => $action['mode'] ?? 'move', 'stage' => $stage, 'workflow_id' => $workflow->id];
    }
}
