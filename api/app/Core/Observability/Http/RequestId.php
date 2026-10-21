<?php

namespace App\Core\Observability\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

use function Sentry\configureScope;

/**
 * NFR-10: every request has an id, `X-Request-Id`. A well-formed id from
 * the load balancer or client is kept (so one id follows a call across
 * hosts); otherwise a new UUID v7 is made. The id is put in Laravel's
 * Context, so it is written on every log line and carried into every job
 * the request queues (Laravel serialises Context into the job payload and
 * restores it when the job runs), and tagged on Sentry events. It is sent
 * back on the response.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    public const CONTEXT_KEY = 'request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = self::accept($request->headers->get(self::HEADER)) ?? (string) Str::uuid7();

        Context::add(self::CONTEXT_KEY, $id);
        $request->headers->set(self::HEADER, $id);

        if (config('sentry.dsn')) {
            configureScope(fn (Scope $scope) => $scope->setTag(self::CONTEXT_KEY, $id));
        }

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /** A caller's id, only when it is short and plain (no log injection). */
    public static function accept(?string $id): ?string
    {
        return $id !== null && preg_match('/^[A-Za-z0-9._-]{8,128}$/', $id) === 1 ? $id : null;
    }
}
