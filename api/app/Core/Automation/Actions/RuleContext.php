<?php

namespace App\Core\Automation\Actions;

use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;

/** The rule an action is validated for: its type, trigger, scope and author. */
final class RuleContext
{
    public function __construct(
        public readonly DocumentType $type,
        public readonly bool $hasDocument,
        public readonly ?string $companyId,
        public readonly ?User $editor,
    ) {}

    public function scope(): DocumentScope
    {
        return new DocumentScope($this->companyId);
    }
}
