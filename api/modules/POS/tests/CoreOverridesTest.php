<?php

namespace Modules\POS\Tests;

use App\Core\Identity\Models\User;
use App\Core\Identity\Pin\OverrideVerifier as CoreVerifier;
use App\Core\Rbac\Scope;
use App\Core\Sync\DeviceSecrets;
use App\Core\Tenancy\Models\Device;
use Illuminate\Support\Str;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleVoid;
use Modules\POS\Sync\CoreOverrides;
use Modules\POS\Sync\OverrideVerifier;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-08 with core's verifier: an online override token, used once for
// the record it names, applies a cashier's void; a resend is the same
// record; the token can't serve another record; an offline override (the
// device signed it with its secret, message v2 with `kid`) applies and is
// flagged `override_offline` for review. The upload records the push.
class CoreOverridesTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    public const PIN = '482619';

    private array $paired;

    private User $cashier;

    private User $manager;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();

        $this->setUpPos();
        app()->instance(OverrideVerifier::class, app(CoreOverrides::class));
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->putJson('/api/v1/me/pos-pin', ['password' => $this->password, 'pin' => self::PIN], $this->headersFor($this->manager))->assertOk();

        // A till paired through the API, so it holds a device secret.
        $id = $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till P'], $this->headersFor())->assertCreated()->json('data.id');
        $code = $this->postJson("/api/v1/devices/{$id}/pairing-code", [], $this->headersFor())->assertOk()->json('code');
        $paired = $this->postJson('/api/v1/devices/pair', ['code' => $code, 'device_name' => 'Till P'])->assertOk();
        $this->paired = ['id' => $id, 'token' => $paired->json('token'), 'secret' => $paired->json('device_secret'), 'kid' => $paired->json('device_secret_kid')];

        $this->ranges(token: $this->paired['token'])->assertOk();
        $this->shift = $this->openShift($this->paired['token']);
    }

    private function sale(int $seq): array
    {
        $body = $this->saleBody($this->shift, $seq, ['receipt_number' => sprintf('R-L01-%06d', $seq)]);
        $this->upload([$body], $this->paired['token'])->assertOk();

        return $body;
    }

    private function void(string $saleId, array $override, ?string $id = null): array
    {
        return ['voids' => [[
            'id' => $id ?? $this->id(), 'sale_id' => $saleId, 'voided_by_id' => $this->cashier->id,
            'voided_at' => now()->toIso8601String(), 'reason' => 'Wrong item', 'override' => $override,
        ]]];
    }

    public function test_an_online_override_applies_the_void_once_and_serves_no_other_record(): void
    {
        $sale = $this->sale(1);
        $voidId = $this->id();
        $token = $this->postJson('/api/v1/pos/override', [
            'manager_user_id' => $this->manager->id, 'pin' => self::PIN, 'permission' => 'pos.sale.void',
            'cashier_user_id' => $this->cashier->id, 'reference' => $voidId,
        ], $this->tillHeaders($this->paired['token']))->assertOk()->json('data.token');

        $body = $this->void($sale['id'], ['token' => $token], $voidId);
        $first = $this->postJson('/api/v1/pos/voids', $body, $this->tillHeaders($this->paired['token']))->assertOk()
            ->assertJsonPath('results.0.void_status', 'applied')
            ->assertJsonPath('results.0.override_verified', true)
            ->assertJsonPath('results.0.flags', []);
        $this->assertSame($first->json('results'), $this->postJson('/api/v1/pos/voids', $body, $this->tillHeaders($this->paired['token']))->json('results'));

        $this->inTenant(function () use ($sale, $voidId) {
            $this->assertSame(Sale::VOIDED, Sale::findOrFail($sale['id'])->status);
            $this->assertSame($this->manager->id, SaleVoid::findOrFail($voidId)->approved_by);
            $this->assertNotNull(Device::findOrFail($this->paired['id'])->last_push_at);
        });

        // The token named that void: another record can't use it.
        $other = $this->sale(2);
        $this->postJson('/api/v1/pos/voids', $this->void($other['id'], ['token' => $token]), $this->tillHeaders($this->paired['token']))
            ->assertUnprocessable()->assertJsonPath('results.0.error.code', 'override_mismatch');
    }

    public function test_an_offline_override_signed_by_the_device_applies_and_is_flagged_for_review(): void
    {
        $sale = $this->sale(1);
        $voidId = $this->id();
        $overrideId = (string) Str::uuid7();
        $at = now()->toIso8601String();
        $message = CoreVerifier::offlineMessage($this->paired['id'], $this->paired['kid'], $overrideId, $this->manager->id, $this->cashier->id, 'pos.sale.void', $voidId, $at);
        $override = [
            'id' => $overrideId, 'kid' => $this->paired['kid'], 'manager_user_id' => $this->manager->id, 'cashier_user_id' => $this->cashier->id,
            'permission' => 'pos.sale.void', 'reference' => $voidId, 'authorised_at' => $at,
            'signature' => DeviceSecrets::encode(hash_hmac('sha256', $message, DeviceSecrets::decode($this->paired['secret']), true)),
        ];

        $this->postJson('/api/v1/pos/voids', $this->void($sale['id'], $override, $voidId), $this->tillHeaders($this->paired['token']))->assertOk()
            ->assertJsonPath('results.0.void_status', 'applied')
            ->assertJsonPath('results.0.flags.0.code', 'override_offline');

        // A forged signature is refused.
        $other = $this->sale(2);
        $this->postJson('/api/v1/pos/voids', $this->void($other['id'], [...$override, 'id' => (string) Str::uuid7(), 'signature' => 'AAAA']), $this->tillHeaders($this->paired['token']))
            ->assertUnprocessable()->assertJsonPath('results.0.error.code', 'override_invalid');
    }
}
