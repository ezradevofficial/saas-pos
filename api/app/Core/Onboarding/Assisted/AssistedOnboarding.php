<?php

namespace App\Core\Onboarding\Assisted;

use App\Core\Audit\AuditContext;
use App\Core\Audit\Auditor;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\Challenges;
use App\Core\Identity\Services\Invitations;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Onboarding;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuditor;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\OwnerGuard;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Events\TenantProvisioned;
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
 * has no usable password or contact and can never sign in (Authenticate
 * refuses it), so staff hold no tenant access at any point. It invites the
 * customer by email or phone as Owner; when the customer accepts, the
 * setup account loses its roles and is deactivated (handOver). Both steps
 * are audited in the tenant's chain and the platform's (AUD-01).
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
    ) {}

    /**
     * @param  array{business_name: string, country: string, locale: string, business_type: string, outlets?: ?int, modules: list<string>, quantities?: array<string, int>, cycle: string, bundle?: ?string, contact_name: string, email?: ?string, phone?: ?string}  $data
     * @return array{tenant_id: string, slug: ?string, invitation_id: string, destination_masked: string}
     */
    public function create(PlatformUser $staff, array $data): array
    {
        $tenantId = (string) Str::uuid7();

        $result = $this->tenants->run($tenantId, fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($staff, $data, $tenantId) {
            ['tenant' => $tenant] = $this->provisioner->provision($tenantId, $data['business_name'], $data['country'], $data['locale']);

            $setup = new User([
                'name' => __('onboarding.assisted.setup_user', ['name' => $staff->name], $data['locale']),
                'email' => "setup-{$tenantId}@assisted.invalid",
                'password' => Str::random(64),
                'locale' => $data['locale'],
                'status' => User::STATUS_ACTIVE,
            ]);
            $setup->forceFill(['is_platform_staff' => true])->save();

            event(new TenantProvisioned($tenant, $setup));

            return $this->audit->actingAs($setup->id, ['platform_user_id' => $staff->id], function () use ($staff, $data, $tenant, $setup) {
                $this->onboarding->chooseBusinessType($data['business_type'], isset($data['outlets']) ? (int) $data['outlets'] : null);
                $this->onboarding->chooseModules([
                    'modules' => $data['modules'],
                    'quantities' => $data['quantities'] ?? [],
                    'cycle' => $data['cycle'],
                    'bundle' => $data['bundle'] ?? null,
                ]);

                $owner = Role::query()->where('is_system', true)->where('template_key', 'owner')->sole();
                $email = $data['email'] ?? null;
                $invitation = $this->invitations->invite($setup, [
                    'name' => $data['contact_name'],
                    'email' => $email,
                    'phone' => $email === null ? ($data['phone'] ?? null) : null,
                    'assignments' => [['role_id' => $owner->id, 'scope_type' => Scope::TENANT, 'scope_id' => null]],
                ]);

                $state = OnboardingState::locked();
                $state->assisted = [
                    'platform_user_id' => $staff->id,
                    'staff_name' => $staff->name,
                    'setup_user_id' => $setup->id,
                    'invitation_id' => $invitation->id,
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

                return ['tenant' => $tenant, 'invitation' => $invitation];
            });
        }));

        /** @var Invitation $invitation */
        $invitation = $result['invitation'];
        $destination = $invitation->email !== null
            ? Challenges::maskDestination('email', $invitation->email)
            : Challenges::maskDestination('sms', (string) $invitation->phone);

        $this->platformAudit->record('platform.tenant.create', null, null, [
            'tenant_id' => $tenantId,
            'business_type' => $data['business_type'],
            'country' => $data['country'],
            'modules' => array_values($data['modules']),
            'invitation_id' => $invitation->id,
            'contact' => $destination,
        ], $staff);

        return [
            'tenant_id' => $tenantId,
            'slug' => $result['tenant']->slug,
            'invitation_id' => $invitation->id,
            'destination_masked' => $destination,
        ];
    }

    /**
     * The customer accepted the setup account's invitation: they are the
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

        $staff = PlatformUser::query()->find($assisted['platform_user_id']);
        $this->platformAudit->record('platform.tenant.handover', null, null, [
            'tenant_id' => $this->tenants->require(),
            'invitation_id' => $invitation->id,
            'owner_id' => $owner->id,
        ], $staff);
    }
}
