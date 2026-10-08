<?php

namespace Tests\Feature\Core\Lists;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\ReadsListExports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Lists and pickers plan, task 3: search, sort and export (EXP-01) on the
// users, invitations, sessions, roles and assignments lists, audited
// (AUD-01), in the reader's scope (RBAC-04), never another tenant's rows
// (TEN-01).
class PeopleAndRolesListsTest extends TestCase
{
    use BuildsOrganisation, ReadsListExports, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpOrganisation();
    }

    private function person(string $name, string $email, string $template = 'cashier', ?Scope $scope = null): User
    {
        return $this->inTenant(function () use ($name, $email, $template, $scope) {
            $user = $this->colleague($this->owner, ['name' => $name, 'email' => $email]);
            $this->assign($user, $this->roles->get($template), $scope ?? Scope::location($this->locationA->id));

            return $user;
        });
    }

    private function invite(string $name, string $email): string
    {
        return $this->postJson('/api/v1/invitations', [
            'name' => $name,
            'email' => $email,
            'assignments' => [['role_id' => $this->roles->get('cashier')->id, 'scope_type' => 'location', 'scope_id' => $this->locationA->id]],
        ], $this->headersFor())->assertCreated()->json('data.id');
    }

    public function test_users_search_sort_and_export(): void
    {
        $zoe = $this->person('Zoe Wanjiru', 'zoe@example.com');
        $amina = $this->person('Amina Ilunga', 'amina@example.com', 'branch_manager', Scope::branch($this->branchB->id));
        $owner = $this->owner->id;

        $this->assertSame([$amina->id, $owner, $zoe->id], $this->listIds('/api/v1/users', $this->headersFor()));
        $this->assertSame([$zoe->id, $owner, $amina->id], $this->listIds('/api/v1/users?sort=-name', $this->headersFor()));
        $this->assertSame([$zoe->id], $this->listIds('/api/v1/users?search=ZOE@', $this->headersFor()));
        $this->getJson('/api/v1/users?sort=password', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');

        $rows = $this->csvRows($this->get('/api/v1/users?format=csv&search=Amina', $this->headersFor())->assertOk());
        $this->assertSame(['Name', 'Email', 'Phone', 'Roles', 'Status', 'Two-factor', 'Last signed in', 'Created'], $rows[0]);
        $this->assertSame(['Amina Ilunga', 'amina@example.com', '', 'Branch Manager at Branch B', 'Active', 'No'], array_slice($rows[1], 0, 6));
        $this->assertCount(2, $rows);

        $fr = $this->csvRows($this->get('/api/v1/users?format=csv&search=Amina&columns[]=roles&columns[]=status', [...$this->headersFor(), 'Accept-Language' => 'fr'])->assertOk());
        $this->assertSame([['Rôles', 'Statut'], ['Branch Manager à Branch B', 'Actif']], $fr);

        $this->inTenant(fn () => $this->assertSame(['csv', 'csv'], AuditEntry::where('action', 'core.user.export')->orderBy('seq')->pluck('after')->pluck('format')->all()));
    }

    public function test_a_users_export_shows_only_roles_and_people_in_the_readers_scope(): void
    {
        $this->person('Amina Ilunga', 'amina@example.com', 'cashier', Scope::location($this->locationB->id));
        $both = $this->person('Baraka Otieno', 'baraka@example.com', 'cashier', Scope::location($this->locationB->id));
        $this->inTenant(fn () => $this->assign($both, $this->roles->get('waiter'), Scope::location($this->locationA->id)));
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $rows = $this->csvRows($this->get('/api/v1/users?format=csv&columns[]=name&columns[]=roles', $this->headersFor($manager))->assertOk());

        // Amina works only in branch B; Baraka's branch B role is left out.
        $this->assertSame([['Name', 'Roles'], ['Baraka Otieno', 'Waiter at Outlet A'], ['Colleague', 'Branch Manager at Branch A']], $rows);
    }

    public function test_invitations_sort_and_export(): void
    {
        $first = $this->invite('Bahati', 'bahati@example.com');
        $second = $this->invite('Asha', 'asha@example.com');

        // Newest first by default; ties (same second) by id.
        $this->assertSame([$second, $first], $this->listIds('/api/v1/invitations', $this->headersFor()));
        $this->assertSame([$second, $first], $this->listIds('/api/v1/invitations?sort=name', $this->headersFor()));
        $this->assertSame([$first, $second], $this->listIds('/api/v1/invitations?sort=-name', $this->headersFor()));
        $this->assertSame([$first], $this->listIds('/api/v1/invitations?search=bahati', $this->headersFor()));
        $this->getJson('/api/v1/invitations?sort=token_hash', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');

        $rows = $this->csvRows($this->get('/api/v1/invitations?format=csv&sort=name', $this->headersFor())->assertOk());
        $this->assertSame(['Name', 'Email', 'Phone', 'Roles', 'Status', 'Invited by', 'Expires', 'Sent'], $rows[0]);
        $this->assertSame(['Asha', 'asha@example.com', '', 'Cashier at Outlet A', 'Pending', 'Owner'], array_slice($rows[1], 0, 6));
        $this->assertCount(3, $rows);

        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.invitation.export')->count()));
    }

    public function test_an_invitations_export_shows_only_grants_and_inviters_in_the_readers_scope(): void
    {
        $cashier = $this->roles->get('cashier')->id;
        $this->postJson('/api/v1/invitations', [
            'name' => 'Bahati',
            'email' => 'bahati@example.com',
            'assignments' => [
                ['role_id' => $cashier, 'scope_type' => 'location', 'scope_id' => $this->locationA->id],
                ['role_id' => $cashier, 'scope_type' => 'location', 'scope_id' => $this->locationB->id],
            ],
        ], $this->headersFor())->assertCreated();
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $columns = '?format=csv&columns[]=name&columns[]=roles&columns[]=invited_by';

        // The Owner (tenant scope) is out of a branch manager's sight; the branch B grant is left out.
        $rows = $this->csvRows($this->get("/api/v1/invitations{$columns}", $this->headersFor($manager))->assertOk());
        $this->assertSame([['Name', 'Roles', 'Invited by'], ['Bahati', 'Cashier at Outlet A', 'Someone you can’t see']], $rows);
        $fr = $this->csvRows($this->get("/api/v1/invitations{$columns}", [...$this->headersFor($manager), 'Accept-Language' => 'fr'])->assertOk());
        $this->assertSame('Une personne que vous ne voyez pas', $fr[1][2]);

        // The Owner sees every grant and their own name.
        $rows = $this->csvRows($this->get("/api/v1/invitations{$columns}", $this->headersFor())->assertOk());
        $this->assertSame(['Bahati', 'Cashier at Outlet A, Cashier at Outlet B', 'Owner'], $rows[1]);
    }

    public function test_sessions_sort_page_and_export_the_users_own_sessions(): void
    {
        $older = $this->signIn($this->owner->email, extra: ['device_name' => 'Back office laptop'])->assertOk()->json('token');
        $token = $this->signIn($this->owner->email, extra: ['device_name' => 'Till 1'])->assertOk()->json('token');
        $headers = $this->bearer($token);
        // Another user's session never shows.
        $this->tokenFor($this->person('Zoe', 'zoe@example.com'));

        $names = fn (string $url) => array_column($this->getJson($url, $headers)->assertOk()->json('data'), 'name');

        $this->assertSame(['Back office laptop', 'Till 1'], $names('/api/v1/auth/sessions?sort=device'));
        $this->assertSame(['Till 1', 'Back office laptop'], $names('/api/v1/auth/sessions?sort=-device'));
        $this->assertSame(['Till 1'], $names('/api/v1/auth/sessions?search=till'));
        $this->getJson('/api/v1/auth/sessions?sort=token', $headers)->assertUnprocessable()->assertJsonValidationErrors('sort');

        // Without paging parameters every session comes back, as before; with them, pages and meta.
        $all = $this->getJson('/api/v1/auth/sessions', $headers)->assertOk();
        $this->assertCount(2, $all->json('data'));
        $this->assertNull($all->json('meta'));
        $paged = $this->getJson('/api/v1/auth/sessions?per_page=1&sort=device', $headers)->assertOk();
        $this->assertSame(['Back office laptop'], array_column($paged->json('data'), 'name'));
        $this->assertSame(2, $paged->json('meta.total'));

        $rows = $this->csvRows($this->get('/api/v1/auth/sessions?format=csv&sort=device', $headers)->assertOk());
        $this->assertSame(['Device', 'Browser or app', 'IP address', 'Last active', 'This device', 'Signed in'], $rows[0]);
        $this->assertSame(['Back office laptop', 'No'], [$rows[1][0], $rows[1][4]]);
        $this->assertSame(['Till 1', 'Yes'], [$rows[2][0], $rows[2][4]]);
        $this->assertCount(3, $rows);
        $this->assertNotSame('', $older);

        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.session.export')->count()));
    }

    public function test_roles_search_sort_and_export(): void
    {
        $custom = $this->inTenant(fn () => $this->role('Stock checker', ['core.audit.view', 'core.user.view'])->id);

        // Default: system roles first, then by name.
        $ids = $this->listIds('/api/v1/roles?per_page=200', $this->headersFor());
        $this->assertSame($custom, end($ids));
        $byName = $this->listIds('/api/v1/roles?per_page=200&sort=name', $this->headersFor());
        $this->assertSame($this->roles->get('accountant')->id, $byName[0]);
        $this->assertSame(array_reverse($byName), $this->listIds('/api/v1/roles?per_page=200&sort=-name', $this->headersFor()));
        $this->assertSame([$custom], $this->listIds('/api/v1/roles?search=stock+checker', $this->headersFor()));
        $this->getJson('/api/v1/roles?sort=guard_name', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');

        $rows = $this->csvRows($this->get('/api/v1/roles?format=csv&search=stock+checker', $this->headersFor())->assertOk());
        $this->assertSame([['Name', 'Description', 'Type', 'Permissions', 'Two-factor', 'Status'], ['Stock checker', '', 'Custom', '2', '', 'Active']], $rows);

        $fr = $this->csvRows($this->get('/api/v1/roles?format=csv&search=stock+checker&columns[]=type', [...$this->headersFor(), 'Accept-Language' => 'fr'])->assertOk());
        $this->assertSame([['Type'], ['Personnalisé']], $fr);

        $this->inTenant(fn () => $this->assertSame(2, AuditEntry::where('action', 'core.role.export')->count()));
    }

    public function test_assignments_sort_page_and_export_in_the_readers_scope(): void
    {
        $user = $this->person('Zoe', 'zoe@example.com', 'waiter', Scope::location($this->locationA->id));
        $this->inTenant(fn () => $this->assign($user, $this->roles->get('cashier'), Scope::location($this->locationB->id)));
        $url = "/api/v1/users/{$user->id}/assignments";
        $roleNames = fn (string $query, ?User $reader = null) => array_column(array_column($this->getJson($url.$query, $this->headersFor($reader))->assertOk()->json('data'), 'role'), 'name');

        $this->assertSame(['Waiter', 'Cashier'], $roleNames(''));
        $this->assertSame(['Cashier', 'Waiter'], $roleNames('?sort=role'));
        $this->assertSame(['Waiter', 'Cashier'], $roleNames('?sort=-role'));
        $this->assertSame(['Cashier'], $roleNames('?search=cash'));
        $this->getJson("{$url}?sort=scope_id", $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');

        // Every assignment without paging parameters (as before); pages with them.
        $this->assertNull($this->getJson($url, $this->headersFor())->json('meta'));
        $this->assertSame(2, $this->getJson("{$url}?per_page=1", $this->headersFor())->assertOk()->json('meta.total'));

        $rows = $this->csvRows($this->get("{$url}?format=csv&sort=role", $this->headersFor())->assertOk());
        $this->assertSame(['Role', 'Level', 'Where', 'Given by', 'Given'], $rows[0]);
        $this->assertSame(['Cashier', 'Location', 'Outlet B'], array_slice($rows[1], 0, 3));
        $this->assertSame(['Waiter', 'Location', 'Outlet A'], array_slice($rows[2], 0, 3));

        // A branch A manager sees (and exports) only the branch A role.
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->assertSame(['Waiter'], $roleNames('', $manager));
        $this->assertSame([['Role'], ['Waiter']], $this->csvRows($this->get("{$url}?format=csv&columns[]=role", $this->headersFor($manager))->assertOk()));

        $this->inTenant(fn () => $this->assertSame(2, AuditEntry::where('action', 'core.assignment.export')->count()));
    }

    public function test_an_export_needs_the_lists_view_permission(): void
    {
        $outsider = $this->person('Amina', 'amina@example.com', 'cashier', Scope::location($this->locationB->id));
        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));

        foreach (['users', 'invitations', 'roles'] as $list) {
            foreach (['csv', 'xlsx', 'pdf'] as $format) {
                $this->refusedExport("/api/v1/{$list}?format={$format}", $cashier)->assertForbidden();
            }
        }

        // A user out of the reader's scope is not found (RBAC-04), export or not.
        $manager = $this->headersFor($this->userWith('branch_manager', Scope::branch($this->branchA->id)));
        $this->refusedExport("/api/v1/users/{$outsider->id}/assignments?format=csv", $manager)->assertNotFound();
        $this->getJson("/api/v1/users/{$outsider->id}/assignments", $manager)->assertNotFound();

        $this->inTenant(fn () => $this->assertSame(0, AuditEntry::where('action', 'like', 'core.%.export')->count()));
    }

    public function test_exports_never_include_another_tenants_rows(): void
    {
        $this->person('Amina Ilunga', 'amina@example.com');
        $this->invite('Bahati', 'bahati@example.com');
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, function () use ($other) {
            $this->colleague($other['user'], ['name' => 'Their person', 'email' => 'theirs@example.com']);
            $this->role('Their role');
        });

        foreach (['users', 'invitations', 'roles'] as $list) {
            foreach (['csv', 'xlsx'] as $format) {
                $response = $this->get("/api/v1/{$list}?format={$format}&status=all", $this->headersFor())->assertOk();
                $text = $format === 'csv' ? implode("\n", array_merge(...$this->csvRows($response))) : implode("\n", array_merge(...$this->xlsxRows($response)));

                $this->assertStringContainsString(['users' => 'Amina Ilunga', 'invitations' => 'Bahati', 'roles' => 'Cashier'][$list], $text, "control: A's {$list} {$format}");

                foreach (['Their person', 'theirs@example.com', 'Their role', $other['user']->email] as $value) {
                    $this->assertStringNotContainsString($value, $text, "A's {$list} {$format} export contains {$value} of another tenant");
                }
            }
        }

        $html = $this->capturePdfHtml(fn () => $this->get('/api/v1/users?format=pdf', $this->headersFor())->assertOk()->streamedContent());
        $this->assertStringContainsString('Amina Ilunga', $html);
        $this->assertStringNotContainsString('Their person', $html);
    }
}
