<?php

namespace App\Core\Sso;

use App\Core\Audit\Auditor;
use App\Core\Branding\PublicBranding;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Sso\Domains\SsoDomains;
use App\Core\Sso\Models\SsoConnection;
use App\Core\Sso\Oidc\OidcClient;
use App\Core\Sso\Saml\SamlClient;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * AUTH-11, ADR 023: a single sign-on from start to ticket.
 *
 * 1. start(): the tenant (by email domain or host), its enabled
 *    connection, and a flow in the cache under sha256(state): nonce and
 *    PKCE verifier (OIDC) or the AuthnRequest id (SAML), the hash of a
 *    browser binding secret, the return origin, and the tester for a test.
 * 2. oidcCallback() / samlCallback(): the flow is spent once, the IdP's
 *    answer is checked in full, the user resolved (SsoLinker), and a
 *    ticket made for the browser: `{origin}/sso/complete#ticket=`. A test
 *    records its outcome instead and returns to the settings page.
 * 3. SsoSignIn::complete() spends the ticket with the binding.
 *
 * Failures with a known tenant are audited as `auth.sso.sign_in_failed`.
 */
class SsoFlows
{
    public const TEST_PATH = '/settings/sso';

    public const LINK_PATH = '/settings/security';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly OidcClient $oidc,
        private readonly SamlClient $saml,
        private readonly SsoLinker $linker,
        private readonly SsoConnections $connections,
        private readonly Auditor $auditor,
    ) {}

    /**
     * The tenant named by an email's domain or the sign-in page's host, with
     * its context set, or null.
     */
    public function tenantFor(?string $email, ?string $host): ?string
    {
        $db = DB::connection(TenantContext::CONNECTION);

        if ($email !== null) {
            $domain = SsoDomains::domainOf($email);
            $tenantId = $domain === null ? null : $db->selectOne('select t as id from public.sso_tenant_for_email_domain(?) as t', [$domain])?->id;
        } else {
            $lookup = PublicBranding::parse($host);
            $tenantId = $lookup === null ? null : $db->selectOne('select t as id from public.sso_tenant_for_host(?, ?) as t', [$lookup['slug'], $lookup['host']])?->id;
        }

        if ($tenantId !== null) {
            $this->tenants->set($tenantId);
        }

        return $tenantId;
    }

    /**
     * Start a sign-in in the current tenant.
     *
     * @return array{connections: list<array{id: string, name: string, provider: string}>}|array{redirect_url: string, binding: string}
     *
     * @throws ApiException 404 sso_not_found
     */
    public function start(?string $connectionId, ?string $origin): array
    {
        $live = SsoConnection::query()->where('enabled', true)->whereNull('archived_at')->orderBy('name')->get();

        if ($connectionId !== null) {
            $live = $live->where('id', $connectionId)->values();
        }

        if ($live->isEmpty()) {
            throw self::notFound();
        }

        if ($live->count() > 1) {
            return ['connections' => $live->map(fn (SsoConnection $c) => ['id' => $c->id, 'name' => $c->name, 'provider' => $c->provider])->all()];
        }

        return $this->begin($live->first(), $this->origin($origin), null);
    }

    /** A connection test by $tester (the settings page), returning there. */
    public function startTest(SsoConnection $connection, User $tester, ?string $origin): array
    {
        return $this->begin($connection, $this->origin($origin), $tester->id);
    }

    /**
     * ADR 023: $user links their own account (Settings, Security), the only
     * way an Owner is ever linked. The connection must be enabled.
     *
     * @throws ApiException 404 sso_not_found
     */
    public function startLink(SsoConnection $connection, User $user, ?string $origin): array
    {
        if (! $connection->isLive()) {
            throw self::notFound();
        }

        return $this->begin($connection, $this->origin($origin), null, $user->id);
    }

    /** The browser's next stop after the IdP sent it back with $state. */
    public function oidcCallback(?string $state, ?string $code, ?string $error): string
    {
        return $this->callback($state, SsoConnection::ENTRA, function (SsoConnection $connection, array $flow) use ($code, $error) {
            if ($error !== null || $code === null || $code === '') {
                throw new SsoFailure('idp_error', $error);
            }

            return $this->oidc->complete($connection, $code, $flow['verifier'], $flow['nonce']);
        });
    }

    public function samlCallback(string $connectionId, ?string $relayState, ?string $response): string
    {
        return $this->callback($relayState, SsoConnection::SAML, function (SsoConnection $connection, array $flow) use ($connectionId, $response) {
            if ($connection->id !== $connectionId || $response === null || $response === '') {
                throw new SsoFailure('saml_invalid', 'The response reached another connection.');
            }

            return $this->saml->validate($connection, $response, $flow['request_id']);
        });
    }

    /** @return array{redirect_url: string, binding: string} */
    private function begin(SsoConnection $connection, string $origin, ?string $testerId, ?string $linkUserId = null): array
    {
        $state = Str::random(43);
        $binding = Str::random(43);
        $flow = [
            'tenant_id' => $this->tenants->require(),
            'connection_id' => $connection->id,
            'binding_hash' => hash('sha256', $binding),
            'origin' => $origin,
            'tester_id' => $testerId,
            'link_user_id' => $linkUserId,
        ];

        try {
            if ($connection->isOidc()) {
                $flow['nonce'] = Str::random(43);
                $flow['verifier'] = Str::random(64);
                $url = $this->oidc->authorizationUrl($connection, $state, $flow['nonce'], $flow['verifier']);
            } else {
                ['url' => $url, 'request_id' => $flow['request_id']] = $this->saml->authnRequest($connection, $state);
            }
        } catch (SsoFailure $e) {
            report($e);

            throw new ApiException(503, 'sso_unavailable', __('sso.errors.unavailable'));
        }

        Cache::put(self::flowKey($state), $flow, (int) config('sso.flow_seconds'));

        return ['redirect_url' => $url, 'binding' => $binding];
    }

    /**
     * Spend the flow, run $check (the IdP's answer for its connection),
     * resolve the user and make the ticket; any failure is a redirect.
     *
     * @param  callable(SsoConnection, array<string, mixed>): SsoAssertion  $check
     */
    private function callback(?string $state, string $protocol, callable $check): string
    {
        $flow = is_string($state) && $state !== '' ? $this->spend(self::flowKey($state)) : null;

        if ($flow === null) {
            return self::failedUrl(self::defaultOrigin(), 'state_invalid');
        }

        $this->tenants->set($flow['tenant_id']);
        $connection = SsoConnection::query()->whereKey($flow['connection_id'])->whereNull('archived_at')->first();
        $test = $flow['tester_id'] !== null;
        $linkUserId = $flow['link_user_id'] ?? null;

        try {
            if ($connection === null || (! $test && ! $connection->enabled) || (($protocol === SsoConnection::SAML) !== (! $connection->isOidc()))) {
                throw new SsoFailure('connection_unavailable');
            }

            $assertion = $check($connection, $flow);

            if ($test) {
                $preview = $this->linker->preview($connection, $assertion);
                $this->connections->recordTest($connection, ['ok' => true, 'email' => $assertion->email, 'mfa' => $assertion->mfa, ...$preview]);

                return $flow['origin'].self::TEST_PATH.'#test=ok';
            }

            if ($linkUserId !== null) {
                $this->linker->linkSelf($connection, $assertion, User::query()->find($linkUserId) ?? throw new SsoFailure('no_account'));

                return $flow['origin'].self::LINK_PATH.'#sso_link=ok';
            }

            $user = $this->linker->resolve($connection, $assertion);
        } catch (SsoFailure $e) {
            if ($connection !== null) {
                $this->recordFailure($connection, $e, $test);
            }

            if ($linkUserId !== null) {
                return $flow['origin'].self::LINK_PATH.'#sso_link=failed&reason='.$e->reason;
            }

            return $test ? $flow['origin'].self::TEST_PATH.'#test=failed' : self::failedUrl($flow['origin'], $e->reason);
        } catch (Throwable $e) {
            report($e);

            if ($connection !== null) {
                $this->recordFailure($connection, new SsoFailure('idp_error', $e->getMessage()), $test);
            }

            if ($linkUserId !== null) {
                return $flow['origin'].self::LINK_PATH.'#sso_link=failed&reason=idp_error';
            }

            return $test ? $flow['origin'].self::TEST_PATH.'#test=failed' : self::failedUrl($flow['origin'], 'idp_error');
        }

        $ticket = Str::random(48);
        Cache::put(self::ticketKey($ticket), [
            'tenant_id' => $flow['tenant_id'],
            'connection_id' => $connection->id,
            'user_id' => $user->id,
            'subject' => $assertion->subject,
            'mfa' => $assertion->mfa,
            'binding_hash' => $flow['binding_hash'],
        ], (int) config('sso.ticket_seconds'));

        return $flow['origin'].'/sso/complete#ticket='.$ticket;
    }

    /**
     * The value under $key, spent: an atomic "used" marker first, so two
     * requests with one state or ticket cannot both have it.
     *
     * @return array<string, mixed>|null
     */
    public function spend(string $key): ?array
    {
        if (! Cache::add($key.':used', true, max((int) config('sso.flow_seconds'), 60))) {
            return null;
        }

        $value = Cache::pull($key);

        return is_array($value) ? $value : null;
    }

    private function recordFailure(SsoConnection $connection, SsoFailure $failure, bool $test): void
    {
        if ($test) {
            $this->connections->recordTest($connection, ['ok' => false, 'reason' => $failure->reason]);

            return;
        }

        rescue(fn () => $this->auditor->record('auth.sso.sign_in_failed', $connection, null, ['reason' => $failure->reason]), report: true);
    }

    /**
     * Where the browser returns: the web app's origin, or the tenant's own
     * subdomain or verified custom domain (https). Anything else falls back
     * to the web app, so the flow is no open redirect.
     */
    public function origin(?string $origin): string
    {
        $default = self::defaultOrigin();
        $parts = is_string($origin) ? parse_url($origin) : false;

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') || isset($parts['query']) || isset($parts['user'])) {
            return $default;
        }

        $normalised = Str::lower($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''));

        if ($normalised === $default) {
            return $default;
        }

        if ($parts['scheme'] !== 'https' || isset($parts['port'])) {
            return $default;
        }

        $host = Str::lower($parts['host']);
        $lookup = PublicBranding::parse($host);
        $tenantId = $lookup === null ? null : DB::connection(TenantContext::CONNECTION)
            ->selectOne('select t as id from public.sso_tenant_for_host(?, ?) as t', [$lookup['slug'], $lookup['host']])?->id;

        return $tenantId !== null && $tenantId === $this->tenants->id() ? 'https://'.$host : $default;
    }

    public static function defaultOrigin(): string
    {
        $parts = parse_url((string) config('app.frontend_url'));

        return Str::lower(($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'localhost').(isset($parts['port']) ? ':'.$parts['port'] : ''));
    }

    public static function flowKey(string $state): string
    {
        return 'sso:flow:'.hash('sha256', $state);
    }

    public static function ticketKey(string $ticket): string
    {
        return 'sso:ticket:'.hash('sha256', $ticket);
    }

    private static function failedUrl(string $origin, string $reason): string
    {
        return $origin.'/sso/complete#error='.(in_array($reason, SsoFailure::REASONS, true) ? $reason : 'idp_error');
    }

    private static function notFound(): ApiException
    {
        $message = __('sso.errors.not_found');

        return new ApiException(404, 'sso_not_found', $message, ['email' => [$message]]);
    }
}
