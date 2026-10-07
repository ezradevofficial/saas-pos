<?php

namespace App\Core\Identity\Http\Middleware;

use App\Core\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Contracts\HasAbilities;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUTH-03: a token issued to a user who must enrol a second factor carries
 * only TwoFactor::ENROL_ABILITY. It reaches the enrolment routes, GET me and
 * sign-out (which opt out of this middleware) and nothing else. Equivalent
 * to Sanctum's `abilities:*`, with the API error envelope.
 */
class EnsureFullAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof HasAbilities && ! $token->can('*')) {
            throw new ApiException(403, 'two_factor_enrollment_required', __('auth.two_factor.enrollment_required'));
        }

        return $next($request);
    }
}
