<?php

namespace App\Core\Identity\Services;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Events\InvitationAccepted;
use App\Core\Identity\IdentityNotices;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Grants;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\RoleManager;
use App\Core\Rbac\Scope;
use App\Core\Sso\SsoEnforcement;
use App\Core\Tenancy\ClosedTenant;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * AUTH-05: invite someone by email or phone with role assignments the
 * inviter may grant (no privilege escalation), and accept with a name and
 * password. The token (40 characters) is sent once and stored as a sha256
 * hash; it is valid for 7 days. Accepting re-checks every assignment, as
 * the inviter, and creates an active user whose contact counts as verified
 * (the link reached it).
 */
class Invitations
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Grants $grants,
        private readonly RoleManager $roles,
        private readonly Auditor $auditor,
        private readonly Authenticate $authenticate,
        private readonly IdentityNotices $notices,
    ) {}

    /**
     * @param  array{name: string, email?: ?string, phone?: ?string, assignments: list<array{role_id: string, scope_type: string, scope_id?: ?string}>}  $data
     */
    public function invite(User $actor, array $data): Invitation
    {
        $assignments = array_map(function (array $a) use ($actor) {
            $scope = $this->grants->scope($a['scope_type'], $a['scope_id'] ?? null);
            $role = $this->grants->role($a['role_id']);
            $this->grants->assertCanInvite($actor, $role, $scope);

            return ['role_id' => $role->id, 'scope_type' => $scope->type, 'scope_id' => $scope->id];
        }, $data['assignments']);

        $token = Str::random(Invitation::TOKEN_LENGTH);
        $email = $data['email'] ?? null;

        $invitation = DB::transaction(function () use ($actor, $data, $assignments, $token, $email) {
            $invitation = Invitation::create([
                'name' => $data['name'],
                'email' => $email,
                'phone' => $email === null ? $data['phone'] : null,
                'assignments' => array_values(array_unique($assignments, SORT_REGULAR)),
                'token_hash' => Invitation::hashToken($token),
                'expires_at' => now()->addDays(Invitation::VALID_DAYS),
                'invited_by' => $actor->id,
            ]);

            $this->auditor->record('core.user.invite', $invitation, null, $invitation->only(['name', 'email', 'phone', 'assignments', 'expires_at']));

            return $invitation;
        });

        $tenant = Tenant::findOrFail($this->tenants->require());

        // Through the Notifier after commit (ADR 009); a failure is reported, the invitation stays.
        rescue(fn () => $this->notices->invited($invitation, $token, $tenant, $actor), report: true);

        return $invitation;
    }

    public function revoke(Invitation $invitation): Invitation
    {
        return DB::transaction(function () use ($invitation) {
            $invitation = Invitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if ($invitation->status() !== Invitation::STATUS_PENDING) {
                return $invitation;
            }

            $invitation->forceFill(['revoked_at' => now()])->save();
            $this->auditor->record('core.user.invitation_revoke', $invitation, null, ['revoked_at' => $invitation->revoked_at]);

            return $invitation;
        });
    }

    /**
     * The open invitation for $token, with its tenant's context set (left
     * set for the rest of the request): 404 unknown, 410 expired, revoked
     * or accepted.
     */
    public function open(string $token): Invitation
    {
        $hash = Invitation::hashToken($token);
        $tenantId = DB::selectOne('select auth_tenant_for_invitation(?) as tenant_id', [$hash])?->tenant_id;
        abort_if($tenantId === null, 404);

        $this->tenants->set($tenantId);
        // Phase 8 review H2: a closed tenant's invitations are not opened or accepted.
        ClosedTenant::refuse($tenantId);
        $invitation = Invitation::where('token_hash', $hash)->first();
        abort_if($invitation === null, 404);

        $this->assertOpen($invitation);

        return $invitation;
    }

    /**
     * Accept: a new active user with the invitation's assignments, and a
     * token issued by Authenticate::issueToken. Requires the invitation's
     * tenant context (open()).
     *
     * @return array{user: User, token: string}
     */
    public function accept(Invitation $invitation, string $name, string $password, string $ip, string $userAgent): array
    {
        // AUTH-11: under enforced SSO the invitee of a verified domain joins by signing in with SSO.
        $ownerRole = Role::query()->whereKey(array_column($invitation->assignments ?? [], 'role_id'))->where('is_owner', true)->exists();

        if (app(SsoEnforcement::class)->refusesInvitationPassword($invitation->email, $ownerRole)) {
            throw new ApiException(403, 'sso_required', __('sso.errors.password_sso_required'));
        }

        $user = $this->createUser($invitation, $name, $password);

        return ['user' => $user, 'token' => $this->authenticate->issueToken($user, $ip, $userAgent)];
    }

    /**
     * AUTH-11, ADR 023: accept an open invitation for an email the tenant's
     * IdP asserted. As accept(), with every assignment re-checked as the
     * inviter, but with an unusable random password (the user may set one by
     * password reset) and no token: single sign-on issues it. Requires the
     * invitation's tenant context. 410 when it is no longer open, 422
     * `invitation_stale`.
     */
    public function acceptWithSso(Invitation $invitation, string $name): User
    {
        return $this->createUser($invitation, $name, Str::password(64));
    }

    private function createUser(Invitation $invitation, string $name, string $password): User
    {
        $field = $invitation->email !== null ? 'email' : 'phone';

        try {
            return DB::transaction(function () use ($invitation, $name, $password, $field) {
                $invitation = Invitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
                $this->assertOpen($invitation);

                $login = $invitation->{$field};
                if (DB::selectOne('select auth_tenant_for_login(?) as tenant_id', [$login])?->tenant_id !== null) {
                    throw ValidationException::withMessages([$field => __('validation.unique', ['attribute' => $field])]);
                }

                $grants = $this->staleChecked($invitation);
                $tenant = Tenant::findOrFail($invitation->tenant_id);

                $user = User::create([
                    'name' => $name,
                    'email' => $invitation->email,
                    'phone' => $invitation->phone,
                    'password' => $password,
                    'locale' => $tenant->default_locale,
                    'status' => User::STATUS_ACTIVE,
                    "{$field}_verified_at" => now(),
                ]);

                foreach ($grants as [$role, $scope]) {
                    $this->roles->createAssignment($user, $role, $scope, $invitation->invited_by);
                }

                $invitation->forceFill(['accepted_at' => now()])->save();
                $this->auditor->record('core.user.invitation_accept', $invitation, null, [
                    'user_id' => $user->id,
                    'accepted_at' => $invitation->accepted_at,
                ], ['user_id' => $user->id]);

                // ONB-08: an assisted tenant's ownership moves to the customer here.
                event(new InvitationAccepted($invitation, $user));

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a sign-up or another invitation for the login.
            throw ValidationException::withMessages([$field => __('validation.unique', ['attribute' => $field])]);
        }
    }

    /**
     * Every assignment re-checked as the inviter would be today: role and
     * scope still exist and are active, and the inviter may still grant
     * them. Anything else: 422 `invitation_stale`.
     *
     * @return list<array{0: Role, 1: Scope}>
     */
    private function staleChecked(Invitation $invitation): array
    {
        $inviter = User::find($invitation->invited_by);

        try {
            abort_if($inviter === null, 404);

            return array_map(function (array $a) use ($inviter) {
                $scope = $this->grants->scope($a['scope_type'], $a['scope_id'] ?? null);
                $role = $this->grants->role($a['role_id']);
                $this->grants->assertCanInvite($inviter, $role, $scope);

                return [$role, $scope];
            }, $invitation->assignments);
        } catch (HttpExceptionInterface) {
            throw new ApiException(422, 'invitation_stale', __('auth.invitation.stale'));
        }
    }

    private function assertOpen(Invitation $invitation): void
    {
        match ($invitation->status()) {
            Invitation::STATUS_PENDING => null,
            Invitation::STATUS_EXPIRED => throw new ApiException(410, 'invitation_expired', __('auth.invitation.expired')),
            Invitation::STATUS_REVOKED => throw new ApiException(410, 'invitation_revoked', __('auth.invitation.revoked')),
            Invitation::STATUS_ACCEPTED => throw new ApiException(410, 'invitation_accepted', __('auth.invitation.accepted')),
        };
    }
}
