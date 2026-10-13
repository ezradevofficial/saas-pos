<?php

namespace App\Core\Onboarding\Listeners;

use App\Core\Identity\Events\InvitationAccepted;
use App\Core\Identity\Models\User;
use App\Core\Onboarding\Assisted\AssistedOnboarding;

/** ONB-08: the customer accepted the setup account's Owner invitation; staff access ends. */
class HandOverOwnership
{
    public function __construct(private readonly AssistedOnboarding $assisted) {}

    public function handle(InvitationAccepted $event): void
    {
        if ((bool) User::query()->whereKey($event->invitation->invited_by)->value('is_platform_staff')) {
            $this->assisted->handOver($event->invitation, $event->user);
        }
    }
}
