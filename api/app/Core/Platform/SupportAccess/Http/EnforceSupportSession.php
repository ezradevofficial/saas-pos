<?php

namespace App\Core\Platform\SupportAccess\Http;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Platform\SupportAccess\Models\SupportAccessGrant;
use App\Core\Platform\SupportAccess\SupportAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * RBAC-13c..RBAC-13f: a support session's requests. Part of the `tenant`
 * middleware group, after RequireTenant, so every tenant route passes it;
 * people's and devices' requests go straight through.
 *
 * - The grant is checked first: approved and not past its end, else 401
 *   `support_access_ended` (the token is refused by authentication too).
 * - The principal's own account routes, the full export and support
 *   access itself are refused (403 `support_route_refused`).
 * - A read-only session refuses every write (403 `support_read_only`),
 *   except ending the session and signing out.
 * - Every request, refused or not, is logged in the tenant's audit and in
 *   the platform chain: method, route, path, status, duration.
 */
class EnforceSupportSession
{
    /** Writes a session may always make. */
    public const ALWAYS = ['POST support-session/end', 'POST auth/sign-out'];

    /** Routes a support principal never reaches (uri patterns, without the api/v1 prefix). */
    public const REFUSED = ['auth/sessions', 'auth/sessions/*', 'tenant-exports', 'tenant-exports/*', 'support-access', 'support-access/*', 'users/*/sign-out-everywhere'];

    /** The principal's own account: readable (the shell needs `me` and its permissions), never changed. */
    public const OWN_ACCOUNT = ['me', 'me/*'];

    public function __construct(private readonly SupportAccess $support) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->support_grant_id === null) {
            return $next($request);
        }

        $started = hrtime(true);
        $uri = preg_replace('#^api/v1/#', '', ltrim((string) $request->route()?->uri(), '/'));
        $key = strtoupper($request->method()).' '.$uri;
        $status = 500;

        try {
            $grant = $this->support->grantOf($user);

            if ($grant === null || ! $grant->isActive()) {
                throw new ApiException(401, 'support_access_ended', __('platform.support.ended'));
            }

            if (! in_array($key, self::ALWAYS, true) && (Str::is(self::REFUSED, $uri) || (! $request->isMethodSafe() && Str::is(self::OWN_ACCOUNT, $uri)))) {
                throw new ApiException(403, 'support_route_refused', __('platform.support.route_refused'));
            }

            if ($grant->mode === SupportAccessGrant::READ && ! $request->isMethodSafe() && ! in_array($key, self::ALWAYS, true)) {
                throw new ApiException(403, 'support_read_only', __('platform.support.read_only'));
            }

            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (Throwable $e) {
            $status = $e instanceof ApiException ? $e->getStatusCode() : (method_exists($e, 'getStatusCode') ? (int) $e->getStatusCode() : 500);

            throw $e;
        } finally {
            rescue(fn () => $this->support->logRequest($user, [
                'method' => strtoupper($request->method()),
                'route' => $uri === '' ? null : $uri,
                'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 500),
                'status' => $status,
                'ms' => intdiv(hrtime(true) - $started, 1_000_000),
            ]), report: true);
        }
    }
}
