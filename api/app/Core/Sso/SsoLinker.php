<?php

namespace App\Core\Sso;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\Invitations;
use App\Core\Limits\Units;
use App\Core\Limits\UsageLimits;
use App\Core\Rbac\Grants;
use App\Core\Rbac\RoleManager;
use App\Core\Sso\Domains\SsoDomains;
use App\Core\Sso\Models\SsoConnection;
use App\Core\Sso\Models\SsoIdentity;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * AUTH-11, ADR 023: the user an IdP assertion names, in the connection's
 * tenant only (row-level security). In order:
 *
 * 1. the email's domain must be one of the tenant's verified domains;
 * 2. a user already linked to (connection, subject);
 * 3. an active user with that verified email: linked now;
 * 4. an open invitation for that email: accepted now;
 * 5. JIT provisioning, when the tenant switched it on;
 * 6. otherwise `no_account`.
 *
 * Platform staff and assisted setup accounts are never linked (ONB-08).
 * Links are audited as `auth.sso.link`, new users as `auth.sso.provision`.
 */
class SsoLinker
{
    public const LINKED = 'linked';

    public const LINK = 'link';

    public const INVITATION = 'invitation';

    public const PROVISION = 'provision';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SsoDomains $domains,
        private readonly SsoSettings $settings,
        private readonly Invitations $invitations,
        private readonly Grants $grants,
        private readonly RoleManager $roles,
        private readonly UsageLimits $limits,
        private readonly Auditor $auditor,
    ) {}

    /**
     * What resolve() would do, changing nothing (a connection test).
     *
     * @return array{action: string, user_id: ?string}
     *
     * @throws SsoFailure
     */
    public function preview(SsoConnection $connection, SsoAssertion $assertion): array
    {
        $this->assertDomain($assertion);

        $linked = $this->linked($connection, $assertion);

        if ($linked !== null) {
            return ['action' => self::LINKED, 'user_id' => $linked->id];
        }

        $user = $this->matching($connection, $assertion);

        if ($user !== null) {
            return ['action' => self::LINK, 'user_id' => $user->id];
        }

        if ($this->openInvitation($assertion->email) !== null) {
            return ['action' => self::INVITATION, 'user_id' => null];
        }

        if ($this->settings->values()['jit_provisioning']) {
            return ['action' => self::PROVISION, 'user_id' => null];
        }

        throw new SsoFailure('no_account');
    }

    /** @throws SsoFailure */
    public function resolve(SsoConnection $connection, SsoAssertion $assertion): User
    {
        $this->assertDomain($assertion);

        $linked = $this->linked($connection, $assertion);

        if ($linked !== null) {
            return $linked;
        }

        $user = $this->matching($connection, $assertion);

        if ($user !== null) {
            return $this->link($connection, $assertion, $user, 'email');
        }

        $invitation = $this->openInvitation($assertion->email);

        if ($invitation !== null) {
            try {
                $user = $this->invitations->acceptWithSso($invitation, $assertion->name ?? $invitation->name);
            } catch (HttpExceptionInterface|ValidationException) {
                throw new SsoFailure('no_account', 'The invitation could not be accepted.');
            }

            return $this->link($connection, $assertion, $user, 'invitation');
        }

        if ($this->settings->values()['jit_provisioning']) {
            return $this->provision($connection, $assertion);
        }

        throw new SsoFailure('no_account');
    }

    private function assertDomain(SsoAssertion $assertion): void
    {
        if (! $this->domains->covers($assertion->email)) {
            throw new SsoFailure('domain_not_verified');
        }
    }

    /** The user linked to this subject, checked usable. */
    private function linked(SsoConnection $connection, SsoAssertion $assertion): ?User
    {
        $identity = SsoIdentity::query()->where('connection_id', $connection->id)->where('subject', $assertion->subject)->first();

        if ($identity === null) {
            return null;
        }

        $user = User::query()->find($identity->user_id);
        $this->assertUsable($user);

        return $user;
    }

    /** The user with this verified email in this tenant (RLS), checked usable and not linked to another subject. */
    private function matching(SsoConnection $connection, SsoAssertion $assertion): ?User
    {
        $user = User::query()->where('email', $assertion->email)->first();

        if ($user === null) {
            return null;
        }

        if ($user->email_verified_at === null) {
            throw new SsoFailure('no_account', 'The email is not verified.');
        }

        $this->assertUsable($user);

        if (SsoIdentity::query()->where('connection_id', $connection->id)->where('user_id', $user->id)->exists()) {
            throw new SsoFailure('identity_conflict');
        }

        return $user;
    }

    private function assertUsable(?User $user): void
    {
        if ($user === null || $user->is_platform_staff) {
            throw new SsoFailure('no_account');
        }

        if ($user->status === User::STATUS_DEACTIVATED) {
            throw new SsoFailure('deactivated');
        }

        if (! $user->isActive()) {
            throw new SsoFailure('no_account', 'The user is not active.');
        }
    }

    private function openInvitation(string $email): ?Invitation
    {
        return Invitation::query()->where('email', $email)
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->latest()->first();
    }

    private function link(SsoConnection $connection, SsoAssertion $assertion, User $user, string $via): User
    {
        try {
            DB::connection(TenantContext::CONNECTION)->transaction(function () use ($connection, $assertion, $user, $via) {
                $identity = SsoIdentity::create([
                    'user_id' => $user->id,
                    'connection_id' => $connection->id,
                    'subject' => $assertion->subject,
                    'email' => $assertion->email,
                ]);

                $this->auditor->record('auth.sso.link', $identity, null, [
                    'user_id' => $user->id,
                    'connection_id' => $connection->id,
                    'email' => $assertion->email,
                    'via' => $via,
                ], ['user_id' => $user->id]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new SsoFailure('identity_conflict');
        }

        return $user;
    }

    /** JIT provisioning with the tenant's default role, re-checked as whoever set it. */
    private function provision(SsoConnection $connection, SsoAssertion $assertion): User
    {
        $values = $this->settings->values();
        $setBy = $values['jit_set_by'] !== null ? User::query()->find($values['jit_set_by']) : null;

        try {
            abort_if($setBy === null || ! $setBy->isActive() || $values['jit_role_id'] === null, 404);
            $role = $this->grants->role($values['jit_role_id']);
            $scope = $this->grants->scope((string) $values['jit_scope_type'], $values['jit_scope_id']);
            abort_if($role->is_owner, 404);
            $this->grants->assertCanInvite($setBy, $role, $scope);
        } catch (HttpExceptionInterface) {
            throw new SsoFailure('jit_stale');
        }

        // Emails are unique across tenants: one in use elsewhere is never created here.
        if (DB::selectOne('select auth_tenant_for_login(?) as tenant_id', [$assertion->email])?->tenant_id !== null) {
            throw new SsoFailure('no_account', 'The email belongs to another account.');
        }

        try {
            return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($connection, $assertion, $role, $scope, $setBy) {
                $this->limits->ensureCanAdd(Units::USER);
                $tenant = Tenant::query()->findOrFail($this->tenants->require());

                $user = User::create([
                    'name' => $assertion->name ?? Str::before($assertion->email, '@'),
                    'email' => $assertion->email,
                    'password' => Str::password(64),
                    'locale' => $tenant->default_locale,
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => CarbonImmutable::now(),
                ]);

                $this->roles->createAssignment($user, $role, $scope, $setBy->id);

                $this->auditor->record('auth.sso.provision', $user, null, [
                    'connection_id' => $connection->id,
                    'email' => $assertion->email,
                    'role_id' => $role->id,
                    'scope_type' => $scope->type,
                    'scope_id' => $scope->id,
                ], ['user_id' => $user->id]);

                return $this->link($connection, $assertion, $user, 'provision');
            });
        } catch (UniqueConstraintViolationException) {
            throw new SsoFailure('no_account', 'The email belongs to another account.');
        } catch (HttpExceptionInterface) {
            // The user limit (LIM-03) refuses another user.
            throw new SsoFailure('jit_stale', 'The usage limit refuses another user.');
        }
    }
}
