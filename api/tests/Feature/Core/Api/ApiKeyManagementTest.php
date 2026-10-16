<?php

namespace Tests\Feature\Core\Api;

use App\Core\Api\ApiKeyToken;
use App\Core\Api\Models\ApiKey;
use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use Illuminate\Database\QueryException;
use Tests\Concerns\BuildsApiKeys;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// API-02 (ADR 020): managing a tenant's API keys: create (secret shown
// once, hash only stored), list, show, update, rotate, revoke, usage;
// permission-denied and other-tenant cases; RBAC-12 audit; no escalation.
class ApiKeyManagementTest extends TestCase
{
    use BuildsApiKeys, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    public function test_an_owner_creates_a_key_whose_secret_is_shown_once_and_stored_as_a_hash(): void
    {
        $response = $this->createKey(['name' => 'Accounting sync'])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.name', 'Accounting sync')
            ->assertJsonPath('data.mode', 'live')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.role.name', $this->roles->get('admin')->name)
            ->assertJsonPath('data.scope.type', 'tenant')
            ->assertJsonPath('data.created_by.id', $this->owner->id);

        $secret = $response->json('secret');
        $token = ApiKeyToken::parse($secret);
        $this->assertNotNull($token);
        $this->assertStringStartsWith('sk_live_', $secret);
        $this->assertSame("sk_live_{$token->publicId}_…", $response->json('data.prefix'));
        $this->assertStringNotContainsString($token->secret, json_encode($response->json('data')));

        $id = $response->json('data.id');
        $this->getJson("/api/v1/api-keys/{$id}", $this->headersFor())->assertOk()->assertJsonMissingPath('secret');
        $this->assertStringNotContainsString($token->secret, $this->getJson('/api/v1/api-keys', $this->headersFor())->assertOk()->getContent());

        $this->inTenant(function () use ($id, $token, $secret) {
            $key = ApiKey::findOrFail($id);
            $this->assertSame(hash('sha256', $token->secret), $key->secret_hash);
            $this->assertNull($key->previous_secret_hash);

            // The principal: a user with status `service`, no contact, one role at the chosen scope.
            $principal = User::findOrFail($key->principal_id);
            $this->assertTrue($principal->isServicePrincipal());
            $this->assertNull($principal->email);
            $this->assertNull($principal->phone);
            $this->assertSame('Accounting sync', $principal->name);
            $assignment = RoleAssignment::where('user_id', $principal->id)->sole();
            $this->assertSame($this->roles->get('admin')->id, $assignment->role_id);
            $this->assertSame(Scope::TENANT, $assignment->scope_type);

            // AUD-01, RBAC-12: the key and its grant are audited, never with the secret.
            $entry = AuditEntry::where('action', 'core.api_key.create')->where('auditable_id', $id)->sole();
            $this->assertSame($this->owner->id, $entry->user_id);
            $this->assertSame($token->publicId, $entry->after['public_id']);
            $this->assertStringNotContainsString($token->secret, json_encode([$entry->before, $entry->after]));
            $this->assertStringNotContainsString($key->secret_hash, json_encode([$entry->before, $entry->after]));
            $this->assertSame(1, AuditEntry::where('action', 'rbac.assignment.create')->where('auditable_id', $assignment->id)->count());
            $this->assertStringNotContainsString($secret, AuditEntry::query()->get()->toJson());
        });
    }

    public function test_keys_need_core_api_key_manage_at_tenant_scope(): void
    {
        $manager = $this->userWith('branch_manager', Scope::of(Scope::BRANCH, $this->branchA->id));
        $key = $this->apiKey();

        $this->getJson('/api/v1/api-keys', $this->headersFor($manager))->assertForbidden();
        $this->createKey([], $manager)->assertForbidden();
        $this->getJson("/api/v1/api-keys/{$key['id']}", $this->headersFor($manager))->assertForbidden();
        $this->patchJson("/api/v1/api-keys/{$key['id']}", ['name' => 'Taken'], $this->headersFor($manager))->assertForbidden();
        $this->postJson("/api/v1/api-keys/{$key['id']}/rotate", [], $this->headersFor($manager))->assertForbidden();
        $this->postJson("/api/v1/api-keys/{$key['id']}/revoke", [], $this->headersFor($manager))->assertForbidden();
        $this->getJson('/api/v1/api-keys/usage', $this->headersFor($manager))->assertForbidden();
        $this->getJson('/api/v1/api-keys/scopes', $this->headersFor($manager))->assertForbidden();
    }

    public function test_a_key_is_never_given_more_than_its_creator_may_grant_nor_an_owner_role(): void
    {
        // A key manager who may assign roles but holds only a few permissions.
        $keeper = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Key keeper', ['core.api_key.manage', 'core.role.assign', 'core.item.view']), Scope::tenant());

            return $user;
        });
        $viewer = $this->inTenant(fn () => $this->role('Item viewer', ['core.item.view']));

        $this->createKey(['role_id' => $this->roles->get('admin')->id], $keeper)->assertForbidden()->assertJsonPath('code', 'cannot_grant');
        $this->createKey(['role_id' => $viewer->id, 'scopes' => ['core.item.read']], $keeper)->assertCreated();
        $this->createKey(['role_id' => $this->roles->get('owner')->id])->assertUnprocessable()->assertJsonPath('code', 'api_key_owner_role');
        $this->createKey(['scopes' => ['core.item.delete']])->assertUnprocessable()->assertJsonValidationErrors('scopes.0');
        $this->createKey(['scope_type' => 'branch'])->assertUnprocessable()->assertJsonValidationErrors('scope_id');
        $this->createKey(['expires_at' => now()->subDay()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    }

    public function test_another_tenants_keys_are_not_found(): void
    {
        $other = $this->otherTenant();
        $theirAdmin = $this->asTenant($other['user']->tenant_id, fn () => Role::where('template_key', 'admin')->value('id'));
        $otherKey = $this->postJson('/api/v1/api-keys', [
            'name' => 'Theirs', 'role_id' => $theirAdmin, 'scope_type' => 'tenant', 'scopes' => ['core.item.read'],
        ], $this->headersFor($other['user']))->assertCreated()->json('data.id');

        $this->getJson("/api/v1/api-keys/{$otherKey}", $this->headersFor())->assertNotFound();
        $this->patchJson("/api/v1/api-keys/{$otherKey}", ['name' => 'Mine'], $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/api-keys/{$otherKey}/rotate", [], $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/api-keys/{$otherKey}/revoke", [], $this->headersFor())->assertNotFound();
        $this->getJson("/api/v1/api-keys/{$otherKey}/usage", $this->headersFor())->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/api-keys', $this->headersFor())->assertOk()->json('data'));
        // A role of the other tenant cannot be granted to a key here.
        $this->createKey(['role_id' => $theirAdmin])->assertNotFound();
        $this->createKey(['scope_type' => 'company', 'scope_id' => $other['company']->id])->assertNotFound();
    }

    public function test_a_key_is_renamed_and_its_scopes_changed_with_an_audit_entry(): void
    {
        $key = $this->apiKey();

        $this->patchJson("/api/v1/api-keys/{$key['id']}", ['name' => 'Renamed', 'scopes' => ['core.item.read']], $this->headersFor())
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.scopes', ['core.item.read']);
        $this->patchJson("/api/v1/api-keys/{$key['id']}", ['scopes' => []], $this->headersFor())->assertUnprocessable();

        $this->inTenant(function () use ($key) {
            $this->assertSame('Renamed', User::findOrFail(ApiKey::findOrFail($key['id'])->principal_id)->name);
            $entry = AuditEntry::where('action', 'core.api_key.update')->where('auditable_id', $key['id'])->sole();
            $this->assertSame(['name' => 'Renamed', 'scopes' => ['core.item.read']], $entry->after);
            $this->assertSame('Integration', $entry->before['name']);
        });
    }

    public function test_rotating_returns_a_new_secret_and_revoking_ends_the_key(): void
    {
        $key = $this->apiKey();
        $rotated = $this->postJson("/api/v1/api-keys/{$key['id']}/rotate", ['overlap_hours' => 2], $this->headersFor())->assertOk();
        $new = $rotated->json('secret');

        $this->assertNotSame($key['secret'], $new);
        $this->assertSame(ApiKeyToken::parse($key['secret'])->publicId, ApiKeyToken::parse($new)->publicId);
        $this->assertNotNull($rotated->json('data.previous_expires_at'));
        $this->postJson("/api/v1/api-keys/{$key['id']}/rotate", ['overlap_hours' => 500], $this->headersFor())->assertUnprocessable();

        $this->postJson("/api/v1/api-keys/{$key['id']}/revoke", [], $this->headersFor())->assertOk()
            ->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.role', null)->assertJsonPath('data.previous_expires_at', null);
        $this->postJson("/api/v1/api-keys/{$key['id']}/revoke", [], $this->headersFor())->assertConflict()->assertJsonPath('code', 'api_key_revoked');
        $this->postJson("/api/v1/api-keys/{$key['id']}/rotate", [], $this->headersFor())->assertConflict();
        $this->patchJson("/api/v1/api-keys/{$key['id']}", ['name' => 'Back'], $this->headersFor())->assertConflict();

        $this->inTenant(function () use ($key) {
            $stored = ApiKey::findOrFail($key['id']);
            $this->assertSame(0, RoleAssignment::where('user_id', $stored->principal_id)->count());
            $this->assertSame(1, AuditEntry::where('action', 'core.api_key.rotate')->where('auditable_id', $key['id'])->count());
            $this->assertSame(1, AuditEntry::where('action', 'core.api_key.revoke')->where('auditable_id', $key['id'])->count());
        });

        $this->getJson('/api/v1/api-keys?status=revoked', $this->headersFor())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/api-keys?status=active', $this->headersFor())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_usage_counts_requests_per_day_per_key_and_for_the_tenant(): void
    {
        $key = $this->apiKey();
        $other = $this->apiKey(['name' => 'Second']);

        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertOk();
        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertOk();
        $this->getJson('/api/v1/companies', $this->keyHeaders($other['secret']))->assertOk();

        $mine = $this->getJson("/api/v1/api-keys/{$key['id']}/usage", $this->headersFor())->assertOk();
        $this->assertCount(30, $mine->json('data'));
        $this->assertSame(['day' => now()->utc()->toDateString(), 'requests' => 2], $mine->json('data.29'));
        $this->assertSame(2, $mine->json('meta.total'));
        $this->getJson('/api/v1/api-keys/usage?days=7', $this->headersFor())->assertOk()->assertJsonCount(7, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson("/api/v1/api-keys/{$key['id']}", $this->headersFor())->assertOk()->assertJsonPath('data.last_used_at', fn ($at) => $at !== null);
    }

    public function test_principals_are_not_people_they_never_sign_in_nor_show_in_users(): void
    {
        $key = $this->apiKey();
        $principal = $this->inTenant(fn () => User::findOrFail(ApiKey::findOrFail($key['id'])->principal_id));

        $this->assertNotContains($principal->id, array_column($this->getJson('/api/v1/users?per_page=200', $this->headersFor())->assertOk()->json('data'), 'id'));
        $this->getJson("/api/v1/users/{$principal->id}", $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/users/{$principal->id}/assignments", ['role_id' => $this->roles->get('admin')->id, 'scope_type' => 'tenant'], $this->headersFor())->assertNotFound();

        // A personal token for the principal (never issued by the app) is refused.
        $token = $this->inTenant(fn () => $principal->createDeviceToken('test', null, null)->plainTextToken);
        $this->getJson('/api/v1/companies', $this->bearer($token))->assertUnauthorized();

        // A principal keeps its status: the database refuses turning it into a person.
        $this->expectException(QueryException::class);
        $this->inTenant(fn () => $principal->forceFill(['status' => User::STATUS_ACTIVE])->save());
    }

    public function test_the_scope_picker_lists_resources_of_active_modules(): void
    {
        $groups = collect($this->getJson('/api/v1/api-keys/scopes', $this->headersFor())->assertOk()->json('data'));
        $core = $groups->firstWhere('module', 'core');

        $this->assertNotNull($core);
        $this->assertContains(['core.item.read', 'core.item.write'], array_column($core['resources'], 'scopes'));
        $this->assertNull($groups->firstWhere('module', 'payroll'), 'inactive modules offer no scopes');
    }
}
