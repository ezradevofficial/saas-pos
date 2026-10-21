<?php

namespace App\Core\Sso;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\Authenticate;
use App\Core\Identity\Services\LoginThrottle;
use App\Core\Identity\Services\SignInResult;
use App\Core\Sso\Models\SsoIdentity;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AUTH-11, ADR 023: the browser hands back its ticket and binding; the
 * sign-in finishes as a password sign-in would after the password:
 * lockout (AUTH-10) and status checks, the app's second factor unless the
 * IdP asserted MFA (AUTH-03), then Authenticate::issueToken (sessions,
 * new-device alerts, `auth.sign_in`). Audited as `auth.sso.sign_in`.
 */
class SsoSignIn
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SsoFlows $flows,
        private readonly Authenticate $authenticate,
        private readonly LoginThrottle $throttle,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @return array{0: SignInResult, 1: bool} the outcome, and whether the IdP asserted MFA
     *
     * @throws ApiException 422 sso_ticket_invalid, 423 locked, 403 deactivated
     */
    public function complete(string $ticket, string $binding, string $ip, string $userAgent, ?string $deviceName): array
    {
        $data = $this->flows->spend(SsoFlows::ticketKey($ticket));

        if ($data === null || ! hash_equals((string) $data['binding_hash'], hash('sha256', $binding))) {
            throw new ApiException(422, 'sso_ticket_invalid', __('sso.errors.ticket_invalid'));
        }

        $this->tenants->set($data['tenant_id']);
        $user = User::query()->find($data['user_id']);

        if ($user === null || $user->is_platform_staff) {
            throw new ApiException(422, 'sso_ticket_invalid', __('sso.errors.ticket_invalid'));
        }

        if ($user->status === User::STATUS_DEACTIVATED) {
            throw new ApiException(403, 'deactivated', __('auth.deactivated'));
        }

        if (! $user->isActive()) {
            throw new ApiException(422, 'sso_ticket_invalid', __('sso.errors.ticket_invalid'));
        }

        if ($this->throttle->isLocked($user)) {
            $seconds = $this->throttle->retryAfter($user);

            throw new ApiException(423, 'locked', LoginThrottle::lockedMessage($seconds), headers: ['Retry-After' => $seconds]);
        }

        $mfa = (bool) $data['mfa'];

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data, $user, $mfa) {
            SsoIdentity::query()->where('connection_id', $data['connection_id'])->where('user_id', $user->id)
                ->update(['last_sign_in_at' => CarbonImmutable::now()]);

            $this->auditor->record('auth.sso.sign_in', $user, null, [
                'connection_id' => $data['connection_id'],
                'idp_mfa' => $mfa,
                'app_two_factor' => ! $mfa && $user->hasTwoFactor(),
            ], ['user_id' => $user->id]);
        });

        if (! $mfa && $user->hasTwoFactor()) {
            return [SignInResult::twoFactorRequired($this->authenticate->twoFactorChallenge($user)), false];
        }

        return [SignInResult::ok($this->authenticate->issueToken($user, $ip, $userAgent, $deviceName, idpMfa: $mfa), $user), $mfa];
    }
}
