<?php

namespace App\Core\Identity\Pin;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\LoginThrottle;
use Illuminate\Support\Facades\Hash;

/**
 * AUTH-06: users changing their own PIN confirm their password (a stolen
 * session alone cannot open the tills). Wrong passwords count towards the
 * account lockout (AUTH-10).
 */
class PasswordCheck
{
    public function __construct(private readonly LoginThrottle $throttle) {}

    /** @throws ApiException 423 `locked`, 422 `invalid_password` */
    public function confirm(User $user, string $password): void
    {
        if ($this->throttle->isLocked($user)) {
            $seconds = $this->throttle->retryAfter($user);

            throw new ApiException(423, 'locked', LoginThrottle::lockedMessage($seconds), headers: ['Retry-After' => $seconds]);
        }

        if (! Hash::check($password, $user->password)) {
            $this->throttle->recordFailure($user);

            throw new ApiException(422, 'invalid_password', __('auth.password.incorrect'), ['password' => [__('auth.password.incorrect')]]);
        }
    }
}
