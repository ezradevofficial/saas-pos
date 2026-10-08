<?php

namespace App\Core\Support\Http;

use App\Core\Support\EnvironmentGuard;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFR-06: no request is served with development drivers outside local and
 * testing (EnvironmentGuard). Global, first in the stack, so `/up` fails too.
 */
class EnforceEnvironment
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        EnvironmentGuard::enforce($this->app);

        return $next($request);
    }
}
