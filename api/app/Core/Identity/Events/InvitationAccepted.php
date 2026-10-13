<?php

namespace App\Core\Identity\Events;

use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An invitation was accepted (AUTH-05): $user now exists with the
 * invitation's assignments. Dispatched inside the accept transaction and
 * the tenant's context, so listeners (the assisted onboarding handover,
 * ONB-08) commit or roll back with it.
 */
class InvitationAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly Invitation $invitation,
        public readonly User $user,
    ) {}
}
