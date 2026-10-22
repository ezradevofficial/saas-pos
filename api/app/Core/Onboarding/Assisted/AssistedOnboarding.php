<?php

namespace App\Core\Onboarding\Assisted;

use App\Core\Audit\AuditContext;
use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\Challenges;
use App\Core\Identity\Services\Invitations;
use App\Core\Notifications\Sms\SmsAvailability;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Onboarding;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuditor;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\OwnerGuard;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\Events\TenantProvisioned;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ONB-08: platform staff (field sales) create a tenant for a customer and
 * hand its ownership over.
 *
 * The tenant is provisioned like a self sign-up (TenantProvisioner) in the
 * customer's country and language, with the business type and modules
 * chosen (the trial starts through TrialStarter). Its first Owner is a
 * setup account that stands for the staff member inside the tenant: it
 * has no usable password or contact and is refused at sign-in, token
 * issue and the tenant middleware, so staff hold no tenant access at any
 * point. It invites the customer by email or phone as Owner; when the
 * customer accepts the current invitation, the setup account loses its
 * roles and is deactivated (handOver). Staff may send a new invitation
 * while the tenant is not handed over (reinvite).
 *
 * Every step is audited in the tenant's chain and, after the tenant's
 * transaction commits, in the platform's (AUD-01). A create for a contact
 * that already has a pending assisted tenant returns that tenant (a retry
 * never makes a second one).
 */
class AssistedOnboarding
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly TenantProvisioner $provisioner,
        private readonly Onboarding $onboarding,
        private readonly Invitations $invitations,
        private readonly Auditor $auditor,
        private readonly AuditContext $audit,
        private readonly PlatformAuditor $platformAudit,
        private readonly OwnerGuard $owners,
        private readonly DueTenants $due,
    ) {}

    /**
     * @param  array{business_name: string, country: string, locale: string, business_type: string, outlets?: ?int, modules: list<string>, quantities?: array<string, int>, cycle: string, bundle?: ?string, contact_name: string, email?: ?string, phone?: ?string}  $data
     * @return array{tenant_id: string, slug: ?string, invitation_id: string, destination_masked: string, existing: bool}
     */
    public function create(PlatformUser $staff, array $data): array
    {
        // AUTH-05: the Owner's invitation by phone goes out by SMS; refused in email-only mode, before any tenant is made.
        if (($data['email'] ?? null) === null) {
            app(SmsAvailability::class)->assertAvailable('phone');
        }

        $contact = (string) (($data['email'] ?? null) ?? $data['phone']);

        // Check, then create, under an advisory lock on the contact: two staff (or a double
        // click) creating for one contact at once make one tenant.
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($staff, $data, $contact) {
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['assisted:'.Str::lower($contact)]);

            return $this->createLocked($staff, $data, $contact);
        });
    }

    /** create(), under the contact's lock. */
    private function createLocked(PlatformUser $staff, array $data, string $contact): array
    {
        // A retry (a lost answer, a double click) finds the tenant it made.
        if (($existing = $this->due->assistedFor($contact)) !== null) {
            return [...$this->summary($existing), 'existing' => true];
        }

        $tenantId = (string) Str::uuid7();

        $invitationId = $this->tenants->run($tenantId, fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($staff, $data, $tenantId, $contact) {
            ['tenant' => $tenant] = $this->provisioner->provision($tenantId, $data['business_name'], $data['country'], $data['locale'], withSubdomain: true);

            $setup = new User([
                'name' => __('onboarding.assisted.setup_user', ['name' => $staff->name], $data['locale']),
                'email' => "setup-{$tenantId}@assisted.invalid",
                'password' => Str::random(64),
                'locale' => $data['locale'],
                'status' => User::STATUS_ACTIVE,
            ]);
            $setup->forceFill(['is_platform_staff' => true])->save();

            event(new TenantProvisioned($tenant, $setup));

            return $this->audit->actingAs($setup->id, ['platform_user_id' => $staff->id], function () use ($staff, $data, $setup, $contact) {
                $this->onboarding->chooseBusinessType($data['business_type'], isset($data['outlets']) ? (int) $data['outlets'] : null);
                $this->onboarding->chooseModules([
                    'modules' => $data['modules'],
                    'quantities' => $data['quantities'] ?? [],
                    'cycle' => $data['cycle'],
                    'bundle' => $data['bundle'] ?? null,
                ]);

                $email = $data['email'] ?? null;
                $invitation = $this->inviteOwner($setup, $data['contact_name'], $email, $email === null ? ($data['phone'] ?? null) : null);

                $state = OnboardingState::locked();
                $state->assisted = [
                    'platform_user_id' => $staff->id,
                    'staff_name' => $staff->name,
                    'setup_user_id' => $setup->id,
                    'invitation_id' => $invitation->id,
                    'contact' => Str::lower($contact),
                    'created_at' => now()->toIso8601String(),
                    'handed_over_at' => null,
                    'owner_id' => null,
                ];
                $state->save();

                $this->auditor->record('core.onboarding.assisted_create', $state, null, [
                    'platform_user_id' => $staff->id,
                    'business_type' => $data['business_type'],
                    'invitation_id' => $invitation->id,
                ]);

                $tenantId = $this->tenants->require();
                $this->afterCommit(fn () => $this->platformAudit->record('platform.tenant.create', null, null, [
                    'tenant_id' => $tenantId,
                    'business_type' => $data['business_type'],
                    'country' => $data['country'],
                    'modules' => array_values($data['modules']),
                    'invitation_id' => $invitation->id,
                    'contact' => self::mask($invitation),
                ], $staff));

                return $invitation->id;
            });
        }));

        return [...$this->summary($tenantId), 'invitation_id' => $invitationId, 'existing' => false];
    }

    /**
     * A new Owner invitation to the same contact while the tenant is not
     * handed over (the first expired or went astray); the previous one is
     * revoked and only the new one hands over.
     *
     * @return array{tenant_id: string, slug: ?string, invitation_id: string, destination_masked: string}
     */
    public function reinvite(PlatformUser $staff, string $tenantId): array
    {
        $this->tenants->run($tenantId, fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($staff) {
            $state = OnboardingState::query()->lockForUpdate()->first();
            $assisted = $state?->assisted;

            if ($assisted === null) {
                abort(404);
            }

            if (($assisted['handed_over_at'] ?? null) !== null) {
                throw new ApiException(409, 'already_handed_over', __('onboarding.errors.already_handed_over'));
            }

            $setup = User::query()->whereKey($assisted['setup_user_id'])->firstOrFail();
            $previous = Invitation::query()->whereKey($assisted['invitation_id'])->firstOrFail();

            $this->audit->actingAs($setup->id, ['platform_user_id' => $staff->id], function () use ($state, $assisted, $setup, $previous, $staff) {
                $this->invitations->revoke($previous);
                $invitation = $this->inviteOwner($setup, $previous->name, $previous->email, $previous->phone);

                $state->assisted = [...$assisted, 'invitation_id' => $invitation->id];
                $state->save();

                $this->auditor->record('core.onboarding.reinvite', $state, ['invitation_id' => $previous->id], ['invitation_id' => $invitation->id, 'platform_user_id' => $staff->id]);

                $tenantId = $this->tenants->require();
                $this->afterCommit(fn () => $this->platformAudit->record('platform.tenant.reinvite', null, ['invitation_id' => $previous->id], [
                    'tenant_id' => $tenantId,
                    'invitation_id' => $invitation->id,
                ], $staff));
            });
        }));

        return $this->summary($tenantId);
    }

    /**
     * ONB-08: assisted tenants not handed over after $days days, for staff
     * to follow up (never deleted).
     *
     * @return list<array{tenant_id: string, business_name: string, staff_name: ?string, contact: string, created_at: ?string}>
     */
    public function awaitingHandover(int $days): array
    {
        return array_map(fn (string $tenantId) => $this->tenants->run($tenantId, function () use ($tenantId) {
            $assisted = OnboardingState::query()->first()?->assisted ?? [];
            $invitation = Invitation::query()->find($assisted['invitation_id'] ?? null);

            return [
                'tenant_id' => $tenantId,
                'business_name' => (string) Tenant::query()->whereKey($tenantId)->value('name'),
                'staff_name' => $assisted['staff_name'] ?? null,
                'contact' => $invitation === null ? '' : self::mask($invitation),
                'created_at' => $assisted['created_at'] ?? null,
            ];
        }), $this->due->awaitingHandover(now()->subDays($days)));
    }

    /**
     * ONB-08: the current tenant's handover as the console shows it, or
     * null when staff did not create it. The contact is masked.
     *
     * @return array{created_at: ?string, staff_name: ?string, contact: string, handed_over_at: ?string}|null
     */
    public function handoverState(): ?array
    {
        $assisted = OnboardingState::query()->first()?->assisted;

        if ($assisted === null) {
            return null;
        }

        $invitation = Invitation::query()->find($assisted['invitation_id'] ?? null);

        return [
            'created_at' => $assisted['created_at'] ?? null,
            'staff_name' => $assisted['staff_name'] ?? null,
            'contact' => $invitation === null ? '' : self::mask($invitation),
            'handed_over_at' => $assisted['handed_over_at'] ?? null,
        ];
    }

    /**
     * The customer accepted the current Owner invitation: they are the
     * Owner now (the invitation gave them the role), and the setup account
     * loses every role and is deactivated, so staff keep no access.
     * Runs inside the accept transaction, in the tenant's context.
     */
    public function handOver(Invitation $invitation, User $owner): void
    {
        $state = OnboardingState::locked();
        $assisted = $state->assisted;

        if ($assisted === null || ($assisted['handed_over_at'] ?? null) !== null || $assisted['invitation_id'] !== $invitation->id) {
            return;
        }

        $setup = User::query()->whereKey($assisted['setup_user_id'])->lockForUpdate()->firstOrFail();

        $this->owners->protect($setup, function () use ($setup) {
            foreach (RoleAssignment::query()->where('user_id', $setup->id)->get() as $assignment) {
                $this->owners->assertAssignmentRemovable($assignment);
                $assignment->delete();
            }

            $setup->forceFill(['status' => User::STATUS_DEACTIVATED])->saveQuietly();
            $setup->tokens()->delete();
            $this->auditor->record('core.user.deactivate', $setup, ['status' => User::STATUS_ACTIVE], ['status' => User::STATUS_DEACTIVATED]);
        });

        $state->assisted = [...$assisted, 'handed_over_at' => now()->toIso8601String(), 'owner_id' => $owner->id];
        $state->save();

        $this->auditor->record('core.onboarding.handover', $state, null, [
            'owner_id' => $owner->id,
            'setup_user_id' => $setup->id,
            'platform_user_id' => $assisted['platform_user_id'],
        ]);

        $tenantId = $this->tenants->require();
        $this->afterCommit(fn () => $this->platformAudit->record('platform.tenant.handover', null, null, [
            'tenant_id' => $tenantId,
            'invitation_id' => $invitation->id,
            'owner_id' => $owner->id,
        ], PlatformUser::query()->find($assisted['platform_user_id'])));
    }

    private function inviteOwner(User $setup, string $name, ?string $email, ?string $phone): Invitation
    {
        $owner = Role::query()->where('is_system', true)->where('template_key', 'owner')->sole();

        return $this->invitations->invite($setup, [
            'name' => $name,
            'email' => $email,
            'phone' => $email === null ? $phone : null,
            'assignments' => [['role_id' => $owner->id, 'scope_type' => Scope::TENANT, 'scope_id' => null]],
        ]);
    }

    /** @return array{tenant_id: string, slug: ?string, invitation_id: string, destination_masked: string} */
    private function summary(string $tenantId): array
    {
        return $this->tenants->run($tenantId, function () use ($tenantId) {
            $invitation = Invitation::query()->findOrFail(OnboardingState::query()->firstOrFail()->assisted['invitation_id']);

            return [
                'tenant_id' => $tenantId,
                'slug' => Tenant::query()->whereKey($tenantId)->value('slug'),
                'invitation_id' => $invitation->id,
                'destination_masked' => self::mask($invitation),
            ];
        });
    }

    private static function mask(Invitation $invitation): string
    {
        return $invitation->email !== null
            ? Challenges::maskDestination('email', $invitation->email)
            : Challenges::maskDestination('sms', (string) $invitation->phone);
    }

    /** The platform's audit is written once the tenant's transaction commits (never for a rolled-back change). */
    private function afterCommit(callable $record): void
    {
        DB::connection(TenantContext::CONNECTION)->afterCommit($record);
    }
}
