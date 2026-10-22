<?php

namespace Tests\Feature\Core\Platform;

use App\Core\Platform\Models\PlatformAccessToken;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformDatabase;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * ADM-07: staff roles and their permissions, staff accounts managed by
 * platform owners, the last active owner protected, two-factor still
 * required, every change audited in the platform chain.
 */
class PlatformStaffTest extends TestCase
{
    use CreatesPlatformStaff, RefreshTenantDatabase, ResetsPlatformTables;

    public function test_each_role_gets_its_platform_permissions_in_me(): void
    {
        $expected = [
            PlatformUser::ROLE_OWNER => array_keys(PlatformUser::PERMISSIONS),
            PlatformUser::ROLE_SUPPORT => ['platform.tenants.view', 'platform.tenants.owner_search', 'platform.tenants.manage', 'platform.tenants.create', 'platform.catalogue.view', 'platform.billing.view', 'platform.support.request', 'platform.marketplace.view'],
            PlatformUser::ROLE_SALES => ['platform.tenants.view', 'platform.tenants.create', 'platform.catalogue.view', 'platform.billing.view', 'platform.deals.manage', 'platform.reports.view'],
            PlatformUser::ROLE_FINANCE => ['platform.tenants.view', 'platform.tenants.manage', 'platform.catalogue.view', 'platform.catalogue.manage', 'platform.billing.view', 'platform.billing.manage', 'platform.deals.manage', 'platform.deals.approve', 'platform.reports.view', 'platform.marketplace.view', 'platform.marketplace.payouts'],
            PlatformUser::ROLE_COMPLIANCE => ['platform.tenants.view', 'platform.tenants.owner_search', 'platform.catalogue.view', 'platform.billing.view', 'platform.packs.view', 'platform.packs.manage', 'platform.packs.approve', 'platform.packs.publish', 'platform.reports.view', 'platform.marketplace.view', 'platform.marketplace.review'],
            PlatformUser::ROLE_ENGINEERING => ['platform.tenants.view', 'platform.catalogue.view', 'platform.support.request', 'platform.packs.view', 'platform.packs.manage', 'platform.packs.approve', 'platform.marketplace.view', 'platform.marketplace.review'],
        ];

        foreach ($expected as $role => $permissions) {
            $abilities = $this->getJson('/api/v1/platform/me', $this->staffBearer($this->staff($role)))->assertOk()->json('data.abilities');
            $this->assertEqualsCanonicalizing($permissions, $abilities, "role {$role}");
        }

        // ADR 013: two-factor stays mandatory; an enrolment token reaches no console route.
        $enrolling = $this->staffBearer($this->staff(PlatformUser::ROLE_OWNER), [PlatformAccessToken::ENROL]);
        $this->getJson('/api/v1/platform/staff', $enrolling)->assertForbidden()->assertJsonPath('code', 'two_factor_enrollment_required');
    }

    public function test_a_platform_owner_invites_changes_and_deactivates_staff(): void
    {
        $owner = $this->staffBearer($me = $this->staff(PlatformUser::ROLE_OWNER));

        $invited = $this->postJson('/api/v1/platform/staff', ['email' => 'Compliance@Example.com', 'name' => 'Wanjiru', 'role' => 'compliance'], $owner)->assertCreated();
        $invited->assertJsonPath('data.status', 'pending')->assertJsonPath('data.role', 'compliance')->assertJsonPath('data.email', 'compliance@example.com');
        $this->assertMatchesRegularExpression('#/platform/setup/[A-Za-z0-9]{48}$#', $invited->json('data.setup_url'));
        $id = $invited->json('data.id');

        $this->patchJson("/api/v1/platform/staff/{$id}", ['role' => 'engineering'], $owner)->assertOk()->assertJsonPath('data.role', 'engineering');
        $this->patchJson("/api/v1/platform/staff/{$id}", ['role' => 'billing'], $owner)->assertStatus(422);
        $this->postJson("/api/v1/platform/staff/{$id}/deactivate", [], $owner)->assertOk()->assertJsonPath('data.status', 'deactivated');
        $this->postJson("/api/v1/platform/staff/{$id}/reactivate", [], $owner)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertCount(2, $this->getJson('/api/v1/platform/staff', $owner)->assertOk()->json('data'));

        $actions = PlatformDatabase::connection()->table('platform_audit_logs')->where('platform_user_id', $me->id)->pluck('action')->all();
        $this->assertContains('platform.user.created', $actions);
        $this->assertContains('platform.staff.role_change', $actions);
        $this->assertContains('platform.staff.deactivate', $actions);
        $this->assertContains('platform.staff.reactivate', $actions);
    }

    public function test_deactivating_revokes_tokens_and_the_last_owner_is_protected(): void
    {
        $owner = $this->staffBearer($me = $this->staff(PlatformUser::ROLE_OWNER));
        $support = $this->staff(PlatformUser::ROLE_SUPPORT);
        $supportBearer = $this->staffBearer($support);

        $this->postJson("/api/v1/platform/staff/{$support->id}/deactivate", [], $owner)->assertOk();
        $this->getJson('/api/v1/platform/tenants', $supportBearer)->assertUnauthorized();

        // The only active owner can neither be demoted nor deactivated, nor deactivate themselves.
        $this->patchJson("/api/v1/platform/staff/{$me->id}", ['role' => 'finance'], $owner)->assertStatus(422)->assertJsonPath('code', 'last_platform_owner');
        $this->postJson("/api/v1/platform/staff/{$me->id}/deactivate", [], $owner)->assertStatus(422)->assertJsonPath('code', 'cannot_deactivate_self');
        $second = $this->staff(PlatformUser::ROLE_OWNER);
        $this->patchJson("/api/v1/platform/staff/{$me->id}", ['role' => 'finance'], $owner)->assertOk();
        $this->assertSame('finance', $me->fresh()->role);
        $this->assertSame('owner', $second->fresh()->role);
    }

    public function test_only_platform_owners_manage_staff(): void
    {
        foreach ([PlatformUser::ROLE_SUPPORT, PlatformUser::ROLE_SALES, PlatformUser::ROLE_FINANCE, PlatformUser::ROLE_COMPLIANCE, PlatformUser::ROLE_ENGINEERING] as $role) {
            $bearer = $this->staffBearer($this->staff($role));
            $this->getJson('/api/v1/platform/staff', $bearer)->assertForbidden();
            $this->postJson('/api/v1/platform/staff', ['email' => 'x@example.com', 'name' => 'X', 'role' => 'owner'], $bearer)->assertForbidden();
        }

        $this->assertFalse(PlatformUser::query()->where('email', 'x@example.com')->exists());
    }
}
