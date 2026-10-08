<?php

namespace Modules\POS\Sync;

use App\Core\Identity\Models\User;

/**
 * Who allowed a restricted action (AUTH-08, RBAC-06): the person who did it
 * ($approver null), or a manager by override ($approver set, $verified
 * when the override proof checked out).
 */
final class Approval
{
    public function __construct(
        public readonly ?User $approver = null,
        public readonly bool $verified = false,
    ) {}

    public function byOverride(): bool
    {
        return $this->approver !== null;
    }

    public function approverId(): ?string
    {
        return $this->approver?->id;
    }
}
