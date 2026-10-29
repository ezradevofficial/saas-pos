<?php

namespace Tests\Feature\Core\Bootstrap;

use App\Core\Bootstrap\ShellBootstrap;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NFR-02: GET bootstrap carries the app shell's payloads, each the body of
// its own endpoint for the same user, or null where the user may not see it.
class BootstrapTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganisation();
    }

    /** Every part against its own endpoint (a refused or empty endpoint is a null part). */
    private function assertMatchesEndpoints(array $headers): array
    {
        $parts = $this->getJson('/api/v1/bootstrap', $headers)->assertOk()->json('data');

        foreach (ShellBootstrap::PARTS as $name => $uri) {
            $response = $this->getJson('/api/v1/'.$uri, $headers);
            $expected = $response->status() === 200 ? $response->json() : null;
            $this->assertSame($expected, $parts[$name], "bootstrap part [{$name}] differs from GET {$uri}");
        }

        return $parts;
    }

    public function test_an_owner_gets_every_part_as_its_endpoint_answers(): void
    {
        $parts = $this->assertMatchesEndpoints($this->headersFor());

        $this->assertNotNull($parts['permissions']);
        $this->assertTrue($parts['permissions']['owner']);
        $this->assertNotNull($parts['usage']);
        $this->assertNotNull($parts['companies']);
        $this->assertSame([], $parts['custom_form_types']['data']);
        $this->assertNull($parts['hr_me'], 'HR is not subscribed');
        $this->assertNull($parts['theme_place'], 'no place asked for');
    }

    public function test_a_cashier_gets_the_same_parts_as_their_endpoints_and_not_the_owners(): void
    {
        $cashier = $this->userWith('cashier', Scope::branch($this->branchA->id));
        $parts = $this->assertMatchesEndpoints($this->headersFor($cashier));

        $this->assertFalse($parts['permissions']['owner']);
        $this->assertNull($parts['usage'], 'usage is for Owners');
        $this->assertNull($parts['support_active'], 'support access is for those who may view it');
    }

    public function test_the_theme_is_also_resolved_at_the_place_asked_for(): void
    {
        $headers = $this->headersFor();
        $parts = $this->getJson('/api/v1/bootstrap?company='.$this->acme->id, $headers)->assertOk()->json('data');
        $own = $this->getJson('/api/v1/config/theme/resolved?company='.$this->acme->id, $headers)->assertOk()->json();

        $this->assertSame($own, $parts['theme_place']);
    }

    public function test_a_place_outside_the_users_scope_is_a_null_part(): void
    {
        $cashier = $this->userWith('cashier', Scope::branch($this->branchA->id));
        $other = $this->otherTenant();

        $parts = $this->getJson('/api/v1/bootstrap?company='.$other['company']->id, $this->headersFor($cashier))->assertOk()->json('data');

        $this->assertNull($parts['theme_place']);
        $this->assertNotNull($parts['theme']);
    }

    public function test_another_tenants_user_never_sees_this_tenants_data(): void
    {
        $other = $this->otherTenant();
        $token = $this->tokenFor($other['user']);

        $parts = $this->getJson('/api/v1/bootstrap', $this->bearer($token))->assertOk()->json('data');

        $this->assertSame([$other['company']->id], array_column($parts['companies']['data'], 'id'));
        $this->assertStringNotContainsString($this->acme->id, json_encode($parts));
        $this->assertStringNotContainsString($this->owner->tenant_id, json_encode($parts));
    }

    public function test_it_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/bootstrap')->assertUnauthorized();
    }

    public function test_a_place_must_be_a_uuid(): void
    {
        $this->getJson('/api/v1/bootstrap?company=nope', $this->headersFor())->assertUnprocessable();
    }
}
