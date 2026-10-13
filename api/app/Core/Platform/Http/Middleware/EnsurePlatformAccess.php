<?php

namespace App\Core\Platform\Http\Middleware;

use App\Core\Http\ApiException;
use App\Core\Platform\Models\PlatformUser;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform routes (ADR 013), after `auth:platform`: only a staff token.
 * With `full` (the default), only a token issued after two-factor; an
 * enrolment token reaches only the routes that pass `enrol`.
 */
class EnsurePlatformAccess
{
    public function handle(Request $request, Closure $next, string $level = 'full'): Response
    {
        $user = $request->user('platform');

        if (! $user instanceof PlatformUser || $user->currentAccessToken() === null) {
            throw new AuthenticationException;
        }

        if ($level !== 'enrol' && (! $user->currentAccessToken()->isFull() || ! $user->hasTwoFactor())) {
            throw new ApiException(403, 'two_factor_enrollment_required', __('auth.two_factor.enrollment_required'));
        }

        return $next($request);
    }
}
