<?php

namespace App\Core\Workflow\Runtime;

use App\Core\Identity\Models\User;
use App\Core\Workflow\Definitions\FlowGraph;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\Models\DocumentWorkflow;

/**
 * One engine operation on one document (start, move, return, cancel):
 * the flow version's graph, the type, the document's scope, its field
 * values (read once, from the type's accessor) and who acts. `checkEnter`
 * is set when a person moves the document, so stages they may not move
 * documents into refuse them (WF-08). `closedGroups` are parallel groups
 * an "any" join has closed during the operation.
 */
final class Run
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /** @var array<string, true> */
    public array $closedGroups = [];

    /** The document's company time zone (conditions read days in it), once known. */
    public ?string $timezone = null;

    public function __construct(
        public readonly DocumentWorkflow $workflow,
        public readonly FlowGraph $flow,
        public readonly DocumentType $type,
        public readonly DocumentScope $scope,
        public readonly ?User $user,
        public readonly bool $checkEnter,
    ) {}

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values ??= $this->type->fieldValues($this->workflow->document_id);
    }

    /** The parallel group of a branch entry ("{group}#{branch}"). */
    public static function groupOf(string $branch): string
    {
        return strstr($branch, '#', true) ?: $branch;
    }

    /** @param list<string> $groups */
    public function inClosedGroup(array $groups): bool
    {
        foreach ($groups as $group) {
            if (isset($this->closedGroups[self::groupOf($group)])) {
                return true;
            }
        }

        return false;
    }
}
