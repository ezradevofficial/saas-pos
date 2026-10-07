<?php

namespace Tests\Feature\Core\Tenancy;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Device;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// TEN-05 devices and pairing, RBAC-04 scoped visibility, AUD-01 audit.
class DeviceApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    private function createDevice(string $name = 'Till 1'): string
    {
        return $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => $name], $this->headersFor())
            ->assertCreated()
            ->json('data.id');
    }

    private function pairingCode(string $deviceId): string
    {
        return $this->postJson("/api/v1/devices/{$deviceId}/pairing-code", [], $this->headersFor())
            ->assertOk()
            ->json('code');
    }

    private function pair(string $code, string $deviceName = 'Front counter tablet')
    {
        return $this->postJson('/api/v1/devices/pair', ['code' => $code, 'device_name' => $deviceName]);
    }

    public function test_the_owner_creates_lists_and_renames_a_device(): void
    {
        $id = $this->createDevice();

        $this->getJson("/api/v1/devices/{$id}", $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.location_id', $this->locationA->id)
            ->assertJsonMissingPath('data.pairing_code_hash');

        $this->getJson("/api/v1/locations/{$this->locationA->id}/devices", $this->headersFor())
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->patchJson("/api/v1/devices/{$id}", ['name' => 'Till 2'], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.name', 'Till 2');
    }

    public function test_a_pairing_code_is_eight_unambiguous_characters_valid_fifteen_minutes(): void
    {
        $id = $this->createDevice();

        $response = $this->postJson("/api/v1/devices/{$id}/pairing-code", [], $this->headersFor())->assertOk();

        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $response->json('code'));
        $this->assertNotNull($response->json('expires_at'));

        $this->inTenant(function () use ($id, $response) {
            $device = Device::findOrFail($id);
            $this->assertSame(hash('sha256', $response->json('code')), $device->pairing_code_hash);
            $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, $device->pairing_code_expires_at->timestamp, 5);
        });
    }

    public function test_pairing_works_once_and_the_token_reaches_devices_me(): void
    {
        $id = $this->createDevice();
        $code = $this->pairingCode($id);

        $response = $this->pair(strtolower($code))->assertOk()
            ->assertJsonPath('device.id', $id)
            ->assertJsonPath('device.status', 'active');
        $token = $response->json('token');
        $this->assertNotEmpty($token);

        $this->getJson('/api/v1/devices/me', $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.location.name', 'Outlet A')
            ->assertJsonPath('data.branch.name', 'Branch A')
            ->assertJsonPath('data.company.name', 'Acme');

        $this->pair($code)->assertUnprocessable()->assertJsonPath('code', 'invalid_pairing_code');

        $this->inTenant(function () use ($id) {
            $device = Device::findOrFail($id);
            $this->assertNull($device->pairing_code_hash);
            $this->assertNotNull($device->paired_at);
            $this->assertTrue(AuditEntry::where('action', 'core.device.pair')->where('auditable_id', $id)->exists());

            $token = PersonalAccessToken::where('tokenable_id', $id)->sole();
            $this->assertSame('Front counter tablet', $token->name);
            $this->assertSame(['device'], $token->abilities);
            $this->assertSame($this->owner->tenant_id, $token->tenant_id);
        });
    }

    public function test_an_expired_or_unknown_code_is_refused(): void
    {
        $code = $this->pairingCode($this->createDevice());

        $this->pair('ZZZZZZZZ')->assertUnprocessable()->assertJsonPath('code', 'invalid_pairing_code');

        $this->travel(16)->minutes();

        $this->pair($code)->assertUnprocessable()->assertJsonPath('code', 'invalid_pairing_code');
    }

    public function test_pairing_validates_and_is_rate_limited(): void
    {
        $this->postJson('/api/v1/devices/pair', [])->assertUnprocessable()->assertJsonValidationErrors(['code', 'device_name']);

        for ($i = 0; $i < 9; $i++) {
            $this->pair('ZZZZZZZZ')->assertUnprocessable();
        }

        $this->pair('ZZZZZZZZ')->assertStatus(429);
    }

    public function test_a_device_token_does_not_idle_out_like_a_back_office_session(): void
    {
        $id = $this->createDevice();
        $token = $this->pair($this->pairingCode($id))->json('token');

        $this->travel(3)->days();

        $this->getJson('/api/v1/devices/me', $this->bearer($token))->assertOk();
    }

    public function test_a_suspended_device_token_is_refused_until_the_device_is_resumed(): void
    {
        $id = $this->createDevice();
        $token = $this->pair($this->pairingCode($id))->json('token');

        $this->postJson("/api/v1/devices/{$id}/suspend", [], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
        // Idempotent: a second suspend changes nothing and is not audited again.
        $this->postJson("/api/v1/devices/{$id}/suspend", [], $this->headersFor())->assertOk();

        $this->getJson('/api/v1/devices/me', $this->bearer($token))->assertUnauthorized();

        $this->postJson("/api/v1/devices/{$id}/resume", [], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->getJson('/api/v1/devices/me', $this->bearer($token))->assertOk();

        $this->inTenant(function () use ($id) {
            $this->assertSame(1, AuditEntry::where('action', 'core.device.suspend')->where('auditable_id', $id)->count());
            $this->assertSame(1, AuditEntry::where('action', 'core.device.resume')->where('auditable_id', $id)->count());
            $this->assertNotNull(Device::findOrFail($id)->paired_at);
        });
    }

    public function test_a_suspended_device_cannot_be_unpaired_and_resume_needs_the_archive_permission(): void
    {
        // Built before any request: a request switches the default guard.
        $pairer = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Pairer', ['core.device.view', 'core.device.pair']), Scope::location($this->locationA->id));

            return $user;
        });
        $id = $this->createDevice();
        $this->pair($this->pairingCode($id))->assertOk();
        $this->postJson("/api/v1/devices/{$id}/suspend", [], $this->headersFor())->assertOk();

        $this->postJson("/api/v1/devices/{$id}/unpair", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'device_suspended');

        // core.device.pair without core.device.archive cannot lift a suspension.
        $this->postJson("/api/v1/devices/{$id}/resume", [], $this->headersFor($pairer))->assertForbidden();
        $this->postJson("/api/v1/devices/{$id}/unpair", [], $this->headersFor($pairer))->assertUnprocessable();

        $pending = $this->createDevice('Till 9');
        $this->postJson("/api/v1/devices/{$pending}/resume", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'device_not_suspended');
    }

    public function test_unpair_is_idempotent(): void
    {
        $id = $this->createDevice();
        $this->pair($this->pairingCode($id))->assertOk();

        $this->postJson("/api/v1/devices/{$id}/unpair", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/devices/{$id}/unpair", [], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.status', 'unpaired');

        $this->inTenant(fn () => $this->assertSame(
            1, AuditEntry::where('action', 'core.device.unpair')->where('auditable_id', $id)->count(),
        ));
    }

    public function test_a_token_of_a_device_no_longer_active_is_refused_even_if_not_revoked(): void
    {
        $id = $this->createDevice();
        $token = $this->pair($this->pairingCode($id))->json('token');

        $this->inTenant(fn () => Device::findOrFail($id)->forceFill(['status' => 'suspended'])->save());

        $this->getJson('/api/v1/devices/me', $this->bearer($token))->assertUnauthorized();
    }

    public function test_unpair_revokes_the_token_and_allows_pairing_again(): void
    {
        $id = $this->createDevice();
        $token = $this->pair($this->pairingCode($id))->json('token');

        $this->postJson("/api/v1/devices/{$id}/unpair", [], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.status', 'unpaired');
        $this->getJson('/api/v1/devices/me', $this->bearer($token))->assertUnauthorized();

        $this->inTenant(fn () => $this->assertTrue(
            AuditEntry::where('action', 'core.device.unpair')->where('auditable_id', $id)->exists(),
        ));

        $this->pair($this->pairingCode($id))->assertOk();
    }

    public function test_a_device_token_cannot_call_back_office_routes_and_a_user_token_cannot_call_devices_me(): void
    {
        $token = $this->pair($this->pairingCode($this->createDevice()))->json('token');

        $this->getJson('/api/v1/companies', $this->bearer($token))->assertUnauthorized();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
        // Refused before route-model binding: existing and unknown ids look the same.
        $this->getJson("/api/v1/companies/{$this->acme->id}", $this->bearer($token))->assertUnauthorized();
        $this->getJson('/api/v1/companies/'.Str::uuid7(), $this->bearer($token))->assertUnauthorized();
        $this->postJson("/api/v1/companies/{$this->acme->id}/archive", [], $this->bearer($token))->assertUnauthorized();
        $this->getJson('/api/v1/devices/me', $this->headersFor())->assertForbidden();
    }

    public function test_a_branch_manager_manages_devices_of_their_branch_only(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $id = $this->createDevice();
        $other = $this->inTenant(fn () => Device::create(['location_id' => $this->locationB->id, 'name' => 'B till'])->id);

        $this->getJson("/api/v1/devices/{$id}", $this->headersFor($manager))->assertOk();
        $this->getJson("/api/v1/devices/{$other}", $this->headersFor($manager))->assertNotFound();
        $this->postJson("/api/v1/devices/{$other}/pairing-code", [], $this->headersFor($manager))->assertNotFound();
        $this->getJson("/api/v1/locations/{$this->locationB->id}/devices", $this->headersFor($manager))->assertNotFound();
        $this->getJson("/api/v1/locations/{$this->locationA->id}/devices", $this->headersFor($manager))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);

        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->postJson("/api/v1/devices/{$id}/pairing-code", [], $this->headersFor($cashier))->assertForbidden();
        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'X'], $this->headersFor($cashier))->assertForbidden();
    }

    public function test_another_tenants_devices_are_not_found(): void
    {
        $other = $this->otherTenant();
        $device = $this->asTenant($other['user']->tenant_id, fn () => Device::create(['location_id' => $other['location']->id, 'name' => 'Theirs']));

        $this->getJson("/api/v1/devices/{$device->id}", $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/devices/{$device->id}/suspend", [], $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/locations/{$other['location']->id}/devices", ['name' => 'X'], $this->headersFor())->assertNotFound();
    }
}
