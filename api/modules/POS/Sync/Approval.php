<?php

namespace Modules\POS\Sync;

use App\Core\Identity\Models\User;

/**
 * Who allowed a restricted action (AUTH-07, AUTH-08, RBAC-06): the person
 * who did it ($approver null) or a manager by override, and whether the
 * server could prove it. An unproven approval is held: money out waits for
 * review in the back office; money in is kept and flagged.
 */
final class Approval
{
    public function __construct(
        public readonly ?User $approver = null,
        public readonly bool $verified = false,
        public readonly bool $offline = false,
    ) {}

    /** Flags for review on an applied action: an offline override (the device checked the PIN). */
    public function reviewFlags(): array
    {
        return $this->verified && $this->offline ? ['override_offline'] : [];
    }

    public function byOverride(): bool
    {
        return $this->approver !== null;
    }

    public function held(): bool
    {
        return ! $this->verified;
    }

    /** The flag naming what could not be proven. */
    public function flag(): string
    {
        return $this->byOverride() ? 'override_unverified' : 'actor_unverified';
    }

    public function approverId(): ?string
    {
        return $this->approver?->id;
    }
}
