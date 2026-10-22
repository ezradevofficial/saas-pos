<?php

namespace App\Core\Platform\SupportAccess\Http;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Platform\SupportAccess\Models\SupportAccessGrant;
use App\Core\Platform\SupportAccess\SupportAccess;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
    public const REFUSED = ['auth/sessions', 'auth/sessions/*', 'tenant-exports', 'tenant-exports/*', 'support-access', 'support-access/*', 'users/*/sign-out-everywhere',
        // Phase 8 review M3 (NFR-08): a data subject's access report never leaves with support staff.
        'privacy/requests/*/report'];

    /**
     * Writes that make a credential, an outbound link or a scheduled action
     * under a permission the session may otherwise hold (review H1): they
     * would outlive the grant.
     */
    public const REFUSED_WRITES = [
        'companies/*/accounting/recurring-journals', 'accounting/recurring-journals/*',
        '*/share', 'devices/*/pairing-code', '*/rotate', '*/whatsapp-token', '*/token',
        'procurement/tender-bidders/*/resend', 'procurement/rfqs/*/issue', 'procurement/tenders/*/publish',
    ];

    /** RBAC-13 (review M6): list exports leave the platform; a support session never downloads them. */
    public const EXPORT_FORMATS = ['csv', 'xlsx', 'pdf'];

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
        $entry = fn (int|string $status) => [
            'method' => strtoupper($request->method()),
            'route' => $uri === '' ? null : $uri,
            'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 500),
            'status' => $status,
            'ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ];
        $logged = false;

        try {
            $grant = $this->support->grantOf($user);

            if ($grant === null || ! $grant->isActive()) {
                throw new ApiException(401, 'support_access_ended', __('platform.support.ended'));
            }

            $refused = Str::is(self::REFUSED, $uri)
                || (! $request->isMethodSafe() && (Str::is(self::OWN_ACCOUNT, $uri) || Str::is(self::REFUSED_WRITES, $uri)))
                || static::asksForExport($request);

            if (! in_array($key, self::ALWAYS, true) && $refused) {
                throw new ApiException(403, 'support_route_refused', __('platform.support.route_refused'));
            }

            if ($grant->mode === SupportAccessGrant::READ && ! $request->isMethodSafe() && ! in_array($key, self::ALWAYS, true)) {
                throw new ApiException(403, 'support_read_only', __('platform.support.read_only'));
            }

            // Review M8: a write is logged before it runs, fail-closed: no record, no change.
            if (! $request->isMethodSafe()) {
                try {
                    $this->support->logRequest($user, $entry('started'));
                } catch (Throwable $e) {
                    report($e);
                    $logged = true;

                    throw new ApiException(503, 'support_audit_unavailable', __('platform.support.audit_unavailable'));
                }
            }

            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (Throwable $e) {
            $status = self::statusOf($e);

            throw $e;
        } finally {
            if (! $logged) {
                try {
                    $this->support->logRequest($user, $entry($status));
                } catch (Throwable $e) {
                    // Reads are served; the missing entry is reported and alerted on.
                    report($e);
                    Log::critical('support session request not audited', ['grant_id' => $user->support_grant_id, 'route' => $uri, 'status' => $status]);
                }
            }
        }
    }

    /**
     * Phase 8 review M4: a list export, asked for in the query string or in
     * the body (Form Requests validate both). ListExport refuses support
     * principals itself too; this answers before the list is even queried.
     */
    public static function asksForExport(Request $request): bool
    {
        foreach ([$request->query('format'), $request->input('format')] as $format) {
            if (is_string($format) && in_array(strtolower($format), self::EXPORT_FORMATS, true)) {
                return true;
            }
        }

        return false;
    }

    /** The HTTP status an exception becomes (review M8: validation 422, authorisation 403). */
    private static function statusOf(Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException => 422,
            $e instanceof AuthorizationException => 403,
            $e instanceof AuthenticationException => 401,
            $e instanceof ModelNotFoundException => 404,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => 500,
        };
    }
}
