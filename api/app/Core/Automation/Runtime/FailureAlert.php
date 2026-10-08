<?php

namespace App\Core\Automation\Runtime;

use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Identity\Models\User;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;

/**
 * AUTO-05: a run failed for good. The tenant's automation administrators
 * (active users holding `core.automation.edit` for the whole tenant) hear
 * about it through the Notifier (`core.automation.failed`), with the
 * rule's name, the safe error and a link to the run.
 */
class FailureAlert
{
    public const EVENT = 'core.automation.failed';

    public function __construct(private readonly Notifier $notifier) {}

    public function send(AutomationRule $rule, AutomationRun $run, ?string $error = null): void
    {
        $this->notifier->send(new NotificationEvent(
            self::EVENT,
            $this->administrators(),
            ['rule_name' => $rule->name, 'error' => $error ?? (string) $run->error, 'attempts' => (string) $run->attempts],
            '/automation-rules/'.$rule->id.'/runs/'.$run->id,
        ));
    }

    /** @return list<string> */
    public function administrators(): array
    {
        $candidates = User::query()->where('status', User::STATUS_ACTIVE)
            ->whereIn('id', RoleAssignment::query()->where('scope_type', Scope::TENANT)->select('user_id'))
            ->orderBy('id')
            ->get();

        return $candidates->filter(fn (User $user) => $user->can('core.automation.edit', Scope::tenant()))->modelKeys();
    }
}
