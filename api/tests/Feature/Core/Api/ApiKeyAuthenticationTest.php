<?php

namespace Tests\Feature\Core\Api;

use App\Core\Api\ApiKeyToken;
use App\Core\Api\Models\ApiKey;
use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\RoleTemplates;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Tenant;
use Tests\Concerns\BuildsApiKeys;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// API-01, API-02 (ADR 020): a key authenticates on public routes with the
// same policies, scope resolver and field rules as people, limited by its
// role's scope and its own scopes; refused elsewhere, when revoked or
// expired, and after a rotation's overlap.
class ApiKeyAuthenticationTest extends TestCase
{
    use BuildsApiKeys, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    public function test_a_key_reads_and_writes_public_routes_and_writes_are_attributed_to_it(): void
    {
        $key = $this->apiKey();

        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertOk()
            ->assertJsonPath('data.0.id', $this->acme->id)
            ->assertHeader('RateLimit-Limit', '120')
            ->assertHeader('RateLimit-Remaining', '119')
            ->assertHeader('RateLimit-Reset');

        $party = $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Wanjiku', 'roles' => ['customer']], $this->keyHeaders($key['secret']))
            ->assertCreated()->json('data.id');

        $this->inTenant(function () use ($key, $party) {
            $principal = ApiKey::findOrFail($key['id'])->principal_id;
            $entry = AuditEntry::where('action', 'core.party.create')->where('auditable_id', $party)->sole();
            // AUD-02: the key's principal is the actor, and the entry names the key.
            $this->assertSame($principal, $entry->user_id);
            $this->assertSame($key['id'], $entry->after['metadata']['api_key_id']);
            $this->assertNotNull(Party::findOrFail($party));
        });
    }

    public function test_routes_that_are_not_public_refuse_keys(): void
    {
        $key = $this->apiKey(['scopes' => ['core.settings.write', 'core.user.write', 'core.api_key.write', 'core.role.write']]);

        foreach ([
            ['GET', '/api/v1/me'],
            ['GET', '/api/v1/me/permissions'],
            ['GET', '/api/v1/auth/sessions'],
            ['POST', '/api/v1/auth/sign-out'],
            ['GET', '/api/v1/users'],
            ['GET', '/api/v1/roles'],
            ['GET', '/api/v1/tenant/settings'],
            ['GET', '/api/v1/api-keys'],
            ['POST', '/api/v1/api-keys'],
            ['GET', '/api/v1/subscription'],
            ['POST', '/api/v1/subscription/subscribe'],
            ['GET', '/api/v1/onboarding'],
            ['GET', '/api/v1/devices/me'],
            ['GET', '/api/v1/sync/pull'],
        ] as [$method, $uri]) {
            $this->json($method, $uri, [], $this->keyHeaders($key['secret']))
                ->assertForbidden()
                ->assertJsonPath('code', 'api_key_route_refused')
                ->assertJsonPath('type', '/developers/errors#api_key_route_refused')
                ->assertJsonPath('status', 403)
                ->assertHeader('Content-Type', 'application/problem+json');
        }

        // Platform routes take only platform tokens.
        $this->getJson('/api/v1/platform/tenants', $this->keyHeaders($key['secret']))->assertUnauthorized();
        // The error in French for a French client.
        $this->getJson('/api/v1/me', $this->keyHeaders($key['secret'], ['Accept-Language' => 'fr']))
            ->assertForbidden()->assertJsonPath('message', __('api.errors.route_refused', [], 'fr'));
    }

    public function test_wrong_unknown_revoked_and_expired_keys_are_refused(): void
    {
        $key = $this->apiKey();
        $token = ApiKeyToken::parse($key['secret']);

        foreach ([
            "sk_live_{$token->publicId}_".str_repeat('A', 40),
            'sk_live_'.str_repeat('z', 16).'_'.$token->secret,
            "sk_live_{$token->publicId}_{$token->secret}x",
            "sk_live_{$token->publicId}",
            'sk_',
        ] as $wrong) {
            $this->getJson('/api/v1/companies', $this->keyHeaders($wrong))->assertUnauthorized()
                ->assertJsonPath('code', 'unauthenticated')->assertHeader('Content-Type', 'application/problem+json');
        }

        $this->postJson("/api/v1/api-keys/{$key['id']}/revoke", [], $this->headersFor())->assertOk();
        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertUnauthorized();

        $expiring = $this->apiKey(['expires_at' => now()->addHour()->toIso8601String()]);
        $this->getJson('/api/v1/companies', $this->keyHeaders($expiring['secret']))->assertOk();
        $this->travel(61)->minutes();
        $this->getJson('/api/v1/companies', $this->keyHeaders($expiring['secret']))->assertUnauthorized();
        // AUTH-09: the owner's session idled out meanwhile; a new sign-in.
        $this->getJson("/api/v1/api-keys/{$expiring['id']}", $this->bearer($this->tokenFor($this->owner)))->assertOk()->assertJsonPath('data.status', 'expired');
    }

    public function test_rotation_keeps_the_previous_secret_working_until_the_overlap_ends(): void
    {
        $key = $this->apiKey();
        $new = $this->postJson("/api/v1/api-keys/{$key['id']}/rotate", ['overlap_hours' => 1], $this->headersFor())->assertOk()->json('secret');

        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertOk();
        $this->getJson('/api/v1/companies', $this->keyHeaders($new))->assertOk();

        $this->travel(61)->minutes();
        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertUnauthorized();
        $this->getJson('/api/v1/companies', $this->keyHeaders($new))->assertOk();

        // No overlap: the previous secret stops at once (AUTH-09: a new sign-in, the first idled out).
        $newer = $this->postJson("/api/v1/api-keys/{$key['id']}/rotate", ['overlap_hours' => 0], $this->bearer($this->tokenFor($this->owner)))->assertOk()->json('secret');
        $this->getJson('/api/v1/companies', $this->keyHeaders($new))->assertUnauthorized();
        $this->getJson('/api/v1/companies', $this->keyHeaders($newer))->assertOk();
    }

    public function test_a_key_never_exceeds_its_scopes(): void
    {
        $key = $this->apiKey(['scopes' => ['core.party.read', 'core.company.read']]);

        $this->getJson('/api/v1/parties', $this->keyHeaders($key['secret']))->assertOk();
        $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Refused', 'roles' => ['customer']], $this->keyHeaders($key['secret']))
            ->assertForbidden()->assertJsonPath('code', 'api_scope_missing')->assertJsonPath('scope', 'core.party.write');
        $this->getJson('/api/v1/items', $this->keyHeaders($key['secret']))
            ->assertForbidden()->assertJsonPath('code', 'api_scope_missing')->assertJsonPath('scope', 'core.item.read');

        // Deeper permission checks follow the scopes too: the Admin role holds
        // core.item.view, the key has no item scope.
        $this->inTenant(function () use ($key) {
            $principal = User::findOrFail(ApiKey::findOrFail($key['id'])->principal_id);
            $this->assertTrue($principal->can('core.party.view'));
            $this->assertFalse($principal->can('core.party.create'), 'read scope only');
            $this->assertFalse($principal->can('core.item.view'), 'no item scope');
            $this->assertTrue($principal->can('core.company.view', $this->acme));
        });
    }

    public function test_a_key_never_exceeds_its_roles_scope(): void
    {
        $key = $this->apiKey([
            'role_id' => $this->roles->get('branch_manager')->id,
            'scope_type' => 'branch',
            'scope_id' => $this->branchA->id,
            'scopes' => ['core.branch.read', 'core.location.read', 'core.item.write'],
        ]);

        $branches = array_column($this->getJson('/api/v1/branches', $this->keyHeaders($key['secret']))->assertOk()->json('data'), 'id');
        $this->assertSame([$this->branchA->id], $branches);
        $this->getJson("/api/v1/branches/{$this->branchA->id}", $this->keyHeaders($key['secret']))->assertOk();
        $this->getJson("/api/v1/branches/{$this->branchB->id}", $this->keyHeaders($key['secret']))->assertNotFound();
        $this->assertSame([$this->locationA->id], array_column($this->getJson('/api/v1/locations', $this->keyHeaders($key['secret']))->assertOk()->json('data'), 'id'));
        // Item archive is not in the Branch Manager's role, whatever the key's scopes.
        $this->inTenant(function () use ($key) {
            $principal = User::findOrFail(ApiKey::findOrFail($key['id'])->principal_id);
            $this->assertFalse($principal->can('core.item.archive'));
            $this->assertFalse($principal->can('core.branch.view', Scope::of(Scope::BRANCH, $this->branchB->id)));
        });
    }

    public function test_a_key_never_reaches_another_tenant(): void
    {
        $key = $this->apiKey();
        $other = $this->otherTenant();

        $this->getJson("/api/v1/companies/{$other['company']->id}", $this->keyHeaders($key['secret']))->assertNotFound();
        $this->assertNotContains($other['company']->id, array_column($this->getJson('/api/v1/companies?status=all', $this->keyHeaders($key['secret']))->assertOk()->json('data'), 'id'));
        $this->patchJson("/api/v1/parties/{$other['company']->id}", ['name' => 'Taken'], $this->keyHeaders($key['secret']))->assertNotFound();
    }

    public function test_test_keys_never_reach_live_tenants_and_live_keys_never_reach_sandboxes(): void
    {
        $live = $this->apiKey();
        $token = ApiKeyToken::parse($live['secret']);
        $this->getJson('/api/v1/companies', $this->keyHeaders("sk_test_{$token->publicId}_{$token->secret}"))->assertUnauthorized();

        // A sandbox tenant's keys start sk_test_ and work only as such.
        $sandboxOwner = $this->createUser([], ['is_sandbox' => true]);
        $this->asTenant($sandboxOwner->tenant_id, function () use ($sandboxOwner) {
            $roles = app(RoleTemplates::class)->provision(Tenant::findOrFail($sandboxOwner->tenant_id));
            $this->assign($sandboxOwner, $roles->get('owner'), Scope::tenant());
            $this->company('Sandbox Co');
        });
        $adminRole = $this->asTenant($sandboxOwner->tenant_id, fn () => Role::where('template_key', 'admin')->value('id'));
        $test = $this->postJson('/api/v1/api-keys', ['name' => 'Dev', 'role_id' => $adminRole, 'scope_type' => 'tenant', 'scopes' => ['core.company.read']], $this->bearer($this->tokenFor($sandboxOwner)))
            ->assertCreated()->assertJsonPath('data.mode', 'test')->json('secret');

        $this->assertStringStartsWith('sk_test_', $test);
        $this->getJson('/api/v1/companies', $this->keyHeaders($test))->assertOk()->assertJsonPath('data.0.name', 'Sandbox Co');
        $sandboxToken = ApiKeyToken::parse($test);
        $this->getJson('/api/v1/companies', $this->keyHeaders("sk_live_{$sandboxToken->publicId}_{$sandboxToken->secret}"))->assertUnauthorized();
    }
}
