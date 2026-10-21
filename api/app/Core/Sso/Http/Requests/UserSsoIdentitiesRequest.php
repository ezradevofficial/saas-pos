<?php

namespace App\Core\Sso\Http\Requests;

use App\Core\Identity\Models\User;
use App\Core\Sso\Models\SsoIdentity;

/**
 * AUTH-11, ADR 023: a user's SSO links as an SSO manager sees them
 * (`core.sso.manage`, tenant scope), and unlinking one. A link of another
 * user, or of another tenant, is not found.
 */
class UserSsoIdentitiesRequest extends SsoManageRequest
{
    public function target(): User
    {
        $user = $this->route('user');
        abort_if(! $user instanceof User, 404);

        return $user;
    }

    public function identity(): SsoIdentity
    {
        $identity = $this->route('sso_identity');
        abort_if(! $identity instanceof SsoIdentity || $identity->user_id !== $this->target()->id || $identity->archived_at !== null, 404);

        return $identity;
    }
}
