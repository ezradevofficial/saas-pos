<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use App\Core\Identity\Notifications\InvitationNotification;
use App\Core\Notifications\Channels\SmsChannel;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-05: invitations (7 days), no privilege escalation, accept issues a token.
class InvitationTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        Notification::fake();
    }

    private function invite(array $overrides = [], ?User $as = null)
    {
        return $this->postJson('/api/v1/invitations', array_merge([
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'assignments' => [[
                'role_id' => $this->roles->get('cashier')->id,
                'scope_type' => 'location',
                'scope_id' => $this->locationA->id,
            ]],
        ], $overrides), $this->headersFor($as));
    }

    /** The plain token of the last invitation sent. */
    private function lastToken(): string
    {
        $tokens = [];

        foreach (Notification::sentNotifications() as $byId) {
            foreach ($byId as $byClass) {
                foreach ($byClass[InvitationNotification::class] ?? [] as $sent) {
                    $tokens[] = $sent['notification']->token;
                }
            }
        }

        $this->assertNotEmpty($tokens, 'No invitation was sent.');

        return end($tokens);
    }

    public function test_an_invitee_accepts_and_signs_in_with_the_invited_assignments(): void
    {
        $id = $this->invite()
            ->assertCreated()
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.token_hash')
            ->json('data.id');
        $token = $this->lastToken();
        $this->assertSame(40, strlen($token));

        $this->getJson("/api/v1/auth/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('name', 'Jane')
            ->assertJsonPath('email', 'jane@example.com')
            ->assertJsonPath('tenant_name', $this->inTenant(fn () => Tenant::findOrFail($this->owner->tenant_id)->name))
            ->assertJsonPath('expires_at', fn (string $at) => abs(now()->addDays(7)->diffInSeconds($at)) < 60);

        $accessToken = $this->postJson("/api/v1/auth/invitations/{$token}/accept", [
            'name' => 'Jane Doe',
            'password' => 'amber-meadow-77',
        ])->assertCreated()
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.name', 'Jane Doe')
            ->json('token');

        $this->getJson('/api/v1/me', $this->bearer($accessToken))
            ->assertOk()
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.email_verified_at', fn ($at) => $at !== null);
        $this->signIn('jane@example.com', 'amber-meadow-77')->assertOk();

        $this->inTenant(function () use ($id) {
            $user = User::where('email', 'jane@example.com')->firstOrFail();
            $assignment = RoleAssignment::where('user_id', $user->id)->sole();
            $this->assertSame($this->roles->get('cashier')->id, $assignment->role_id);
            $this->assertSame('location', $assignment->scope_type);
            $this->assertSame($this->locationA->id, $assignment->scope_id);
            $this->assertSame($this->owner->id, $assignment->created_by);
            $this->assertNotNull(Invitation::findOrFail($id)->accepted_at);
            $this->assertSame(1, AuditEntry::where('action', 'core.user.invite')->count());
            $this->assertSame(1, AuditEntry::where('action', 'core.user.invitation_accept')->where('auditable_id', $id)->count());
            // AUTH-05: the sign-in is the token of Authenticate::issueToken.
            $this->assertTrue(AuditEntry::where('action', 'auth.sign_in')->where('user_id', $user->id)->exists());
        });

        // Used once.
        $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['name' => 'X', 'password' => 'amber-meadow-77'])
            ->assertStatus(410)
            ->assertJsonPath('code', 'invitation_accepted');
    }

    public function test_an_invitation_expires_after_seven_days(): void
    {
        $this->invite()->assertCreated();
        $token = $this->lastToken();

        $this->travel(8)->days();

        $this->getJson("/api/v1/auth/invitations/{$token}")
            ->assertStatus(410)
            ->assertJsonPath('code', 'invitation_expired');
        $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['name' => 'Jane', 'password' => 'amber-meadow-77'])
            ->assertStatus(410)
            ->assertJsonPath('code', 'invitation_expired');
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->getJson('/api/v1/auth/invitations/'.str_repeat('a', 40))->assertNotFound();
        $this->postJson('/api/v1/auth/invitations/'.str_repeat('a', 40).'/accept', ['name' => 'J', 'password' => 'amber-meadow-77'])
            ->assertNotFound();
    }

    public function test_accepting_checks_the_tenant_password_policy(): void
    {
        $this->inTenant(fn () => Tenant::findOrFail($this->owner->tenant_id)
            ->forceFill(['settings' => ['password_min_length' => 14]])->save());
        $this->invite()->assertCreated();

        $this->postJson("/api/v1/auth/invitations/{$this->lastToken()}/accept", ['name' => 'Jane', 'password' => 'amber-meadow7'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_a_branch_manager_cannot_invite_beyond_their_scope_or_permissions(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        // Within their branch, a role whose permissions they hold.
        $this->invite([], $manager)->assertCreated();

        // A company-scope assignment is above their own scope.
        $this->invite(['email' => 'b@example.com', 'assignments' => [[
            'role_id' => $this->roles->get('cashier')->id, 'scope_type' => 'company', 'scope_id' => $this->acme->id,
        ]]], $manager)->assertForbidden()->assertJsonPath('code', 'cannot_grant');

        // A role carrying permissions they do not hold (Admin: core.*).
        $this->invite(['email' => 'c@example.com', 'assignments' => [[
            'role_id' => $this->roles->get('admin')->id, 'scope_type' => 'branch', 'scope_id' => $this->branchA->id,
        ]]], $manager)->assertForbidden()->assertJsonPath('code', 'cannot_grant');

        // Another branch's location is out of scope: not found.
        $this->invite(['email' => 'd@example.com', 'assignments' => [[
            'role_id' => $this->roles->get('cashier')->id, 'scope_type' => 'location', 'scope_id' => $this->locationB->id,
        ]]], $manager)->assertNotFound();
    }

    public function test_inviting_takes_the_invite_permission_at_each_assignment_scope(): void
    {
        $inviter = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Inviter', ['core.user.invite']), Scope::location($this->locationA->id));
            // Every permission of the cashier template, so only the invite permission is missing.
            $this->assign($user, $this->role('Assigner', ['core.role.assign', ...$this->roles->get('cashier')->permissionNames()]), Scope::branch($this->branchA->id));

            return $user;
        });

        // core.role.assign covers branch A, core.user.invite does not.
        $this->invite(['assignments' => [[
            'role_id' => $this->roles->get('cashier')->id, 'scope_type' => 'branch', 'scope_id' => $this->branchA->id,
        ]]], $inviter)->assertForbidden()->assertJsonPath('code', 'cannot_grant');

        $this->invite([], $inviter)->assertCreated();
    }

    public function test_revoking_takes_covering_every_assignment_of_the_invitation(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $cashier = $this->roles->get('cashier')->id;
        $id = $this->invite(['assignments' => [
            ['role_id' => $cashier, 'scope_type' => 'location', 'scope_id' => $this->locationA->id],
            ['role_id' => $cashier, 'scope_type' => 'location', 'scope_id' => $this->locationB->id],
        ]])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/invitations/{$id}/revoke", [], $this->headersFor($manager))->assertForbidden();

        $own = $this->invite(['email' => 'mine@example.com'], $manager)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/invitations/{$own}/revoke", [], $this->headersFor($manager))->assertOk();
    }

    public function test_only_an_owner_grants_the_owner_role(): void
    {
        $admin = $this->userWith('admin', Scope::tenant());
        $owner = ['role_id' => $this->roles->get('owner')->id, 'scope_type' => 'tenant', 'scope_id' => $this->owner->tenant_id];

        $this->invite(['assignments' => [$owner]], $admin)->assertForbidden()->assertJsonPath('code', 'cannot_grant');
        $this->invite(['assignments' => [$owner]])->assertCreated();
    }

    public function test_assignment_scopes_and_roles_must_exist_in_the_tenant(): void
    {
        $other = $this->otherTenant();

        $this->invite(['assignments' => [[
            'role_id' => $this->roles->get('cashier')->id, 'scope_type' => 'location', 'scope_id' => $other['location']->id,
        ]]])->assertNotFound();

        $this->invite(['assignments' => [[
            'role_id' => $this->roles->get('cashier')->id, 'scope_type' => 'branch', 'scope_id' => $this->locationA->id,
        ]]])->assertNotFound();

        $otherRole = $this->asTenant($other['user']->tenant_id, fn () => Role::where('template_key', 'cashier')->value('id'));
        $this->invite(['assignments' => [[
            'role_id' => $otherRole, 'scope_type' => 'location', 'scope_id' => $this->locationA->id,
        ]]])->assertNotFound();

        $this->inTenant(fn () => $this->locationA->archive());
        $this->invite()->assertUnprocessable()->assertJsonPath('code', 'parent_archived');
    }

    public function test_an_invitation_needs_assignments_and_one_contact(): void
    {
        $this->invite(['assignments' => []])->assertUnprocessable()->assertJsonValidationErrors(['assignments']);
        $this->invite(['email' => null])->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->invite(['phone' => '+254700000001'])->assertUnprocessable();
        $this->invite(['assignments' => [['role_id' => 'x', 'scope_type' => 'planet', 'scope_id' => 'y']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assignments.0.role_id', 'assignments.0.scope_type', 'assignments.0.scope_id']);
    }

    public function test_a_login_registered_in_any_tenant_cannot_be_invited(): void
    {
        $other = $this->otherTenant();

        $this->invite(['email' => strtoupper($other['user']->email)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_a_phone_invitation_is_sent_by_sms(): void
    {
        $this->invite(['email' => null, 'phone' => '0712 345 678'])
            ->assertCreated()
            ->assertJsonPath('data.phone', '+254712345678');

        Notification::assertSentOnDemand(InvitationNotification::class, function ($notification, array $channels, AnonymousNotifiable $notifiable) {
            return $channels === [SmsChannel::class] && $notifiable->routeNotificationFor('sms') === '+254712345678'
                && str_contains($notification->toSms($notifiable), $notification->token);
        });

        $token = $this->postJson("/api/v1/auth/invitations/{$this->lastToken()}/accept", ['name' => 'Jo', 'password' => 'amber-meadow-77'])
            ->assertCreated()
            ->json('token');
        $this->getJson('/api/v1/me', $this->bearer($token))
            ->assertJsonPath('data.phone', '+254712345678')
            ->assertJsonPath('data.phone_verified_at', fn ($at) => $at !== null);
    }

    public function test_a_revoked_invitation_cannot_be_accepted(): void
    {
        $id = $this->invite()->assertCreated()->json('data.id');
        $token = $this->lastToken();

        $this->postJson("/api/v1/invitations/{$id}/revoke", [], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['name' => 'Jane', 'password' => 'amber-meadow-77'])
            ->assertStatus(410)
            ->assertJsonPath('code', 'invitation_revoked');
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.user.invitation_revoke')->count()));

        // The list shows open invitations by default; a revoked one only when asked for.
        $this->getJson('/api/v1/invitations', $this->headersFor())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/invitations?status=revoked', $this->headersFor())->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_an_assignment_no_longer_valid_makes_the_invitation_stale(): void
    {
        $role = $this->inTenant(fn () => $this->role('Temp', ['core.location.view']));
        $this->invite(['assignments' => [[
            'role_id' => $role->id, 'scope_type' => 'branch', 'scope_id' => $this->branchA->id,
        ]]])->assertCreated();
        $token = $this->lastToken();

        $this->inTenant(fn () => $role->archive());

        $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['name' => 'Jane', 'password' => 'amber-meadow-77'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invitation_stale');
        $this->inTenant(fn () => $this->assertFalse(User::where('email', 'jane@example.com')->exists()));
    }

    public function test_invitations_are_listed_in_scope_and_other_tenants_ids_are_not_found(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->invite()->assertCreated();
        $this->invite(['email' => 'b@example.com', 'assignments' => [[
            'role_id' => $this->roles->get('cashier')->id, 'scope_type' => 'location', 'scope_id' => $this->locationB->id,
        ]]])->assertCreated();

        $this->getJson('/api/v1/invitations', $this->headersFor())->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/invitations', $this->headersFor($manager))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'jane@example.com');

        $other = $this->otherTenant();
        $theirs = $this->asTenant($other['user']->tenant_id, fn () => Invitation::create([
            'name' => 'X', 'email' => 'x@example.com', 'assignments' => [], 'token_hash' => hash('sha256', 'x'),
            'expires_at' => now()->addDay(), 'invited_by' => $other['user']->id,
        ])->id);

        $this->postJson("/api/v1/invitations/{$theirs}/revoke", [], $this->headersFor())->assertNotFound();
    }
}
