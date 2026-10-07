<?php

namespace App\Core\Identity\Http\Middleware;

use App\Core\Identity\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUTH-09, TEN-05: back-office routes accept only a person's token. A POS
 * device token (TEN-05) is refused as unauthenticated.
 */
class EnsureUserToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
