<?php

namespace Modules\POS\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Pin\OverrideVerifier as CoreVerifier;
use App\Core\Rbac\Models\LimitRule;
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
// POS-09, AUD-01: a refund's or void's flags reach its sale's review
// (`refund_flagged` / `void_flagged`), re-opening a reviewed sale, and the
// sale detail shows them.
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

    /** An offline override the paired device signs, for $reference, at $at. */
    private function signed(string $reference, string $at, string $permission = 'pos.sale.void'): array
    {
        $id = (string) Str::uuid7();
        $message = CoreVerifier::offlineMessage($this->paired['id'], $this->paired['kid'], $id, $this->manager->id, $this->cashier->id, $permission, $reference, $at);

        return [
            'id' => $id, 'kid' => $this->paired['kid'], 'manager_user_id' => $this->manager->id, 'cashier_user_id' => $this->cashier->id,
            'permission' => $permission, 'reference' => $reference, 'authorised_at' => $at,
            'signature' => DeviceSecrets::encode(hash_hmac('sha256', $message, DeviceSecrets::decode($this->paired['secret']), true)),
        ];
    }

    public function test_an_offline_override_needs_a_strict_iso_time_and_single_line_fields(): void
    {
        $sale = $this->sale(1);
        $device = $this->inTenant(fn () => Device::query()->findOrFail($this->paired['id']));

        // Signed correctly, but not an ISO 8601 time with a zone (PHP would read these loosely).
        foreach (['2026-10-09 10:00:00', 'now', '@1791540000', '2026-10-09T10:00:00'] as $at) {
            $voidId = $this->id();
            $this->postJson('/api/v1/pos/voids', $this->void($sale['id'], $this->signed($voidId, $at), $voidId), $this->tillHeaders($this->paired['token']))
                ->assertUnprocessable()->assertJsonValidationErrors(['voids.0.override.authorised_at']);

            try {
                $this->inTenant(fn () => app(CoreVerifier::class)->redeem($device, $this->signed($voidId, $at), 'pos.sale.void', $voidId));
                $this->fail("Accepted authorised_at {$at}");
            } catch (ApiException $e) {
                $this->assertSame('override_invalid', $e->errorCode);
            }
        }

        // A line break would let a signed field spill into the next one of the signed message.
        foreach (['permission', 'reference'] as $field) {
            $voidId = $this->id();
            $override = $field === 'permission'
                ? $this->signed($voidId, now()->toIso8601String(), "pos.sale.void\r\n{$voidId}")
                : $this->signed("{$voidId}\r\npos.sale.void", now()->toIso8601String());
            $this->postJson('/api/v1/pos/voids', $this->void($sale['id'], $override, $voidId), $this->tillHeaders($this->paired['token']))
                ->assertUnprocessable()->assertJsonValidationErrors(["voids.0.override.{$field}"]);

            try {
                $this->inTenant(fn () => app(CoreVerifier::class)->redeem($device, $override, 'pos.sale.void', $voidId));
                $this->fail("Accepted a line break in {$field}");
            } catch (ApiException $e) {
                $this->assertSame('override_invalid', $e->errorCode);
            }
        }
    }

    private function refund(string $saleId, int $seq): array
    {
        $id = $this->id();
        $sale = $this->inTenant(fn () => Sale::query()->with('lines')->findOrFail($saleId));

        return [
            'id' => $id, 'sale_id' => $saleId, 'shift_id' => $this->shift, 'cashier_id' => $this->cashier->id,
            'receipt_seq' => $seq, 'receipt_number' => sprintf('RF-L01-%06d', $seq), 'refunded_at' => now()->toIso8601String(),
            'reason' => 'Damaged', 'total_minor' => '56250',
            'lines' => [['id' => $this->id(), 'sale_line_id' => $sale->lines[0]->id, 'qty' => '1']],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => '56250', 'amount_in_sale_minor' => '56250']],
            'override' => $this->signed($id, now()->toIso8601String(), 'pos.sale.refund'),
        ];
    }

    public function test_a_refund_with_an_offline_override_flags_its_sale_and_reopens_a_reviewed_one(): void
    {
        $this->ranges('pos.refund', token: $this->paired['token'])->assertOk();
        $this->inTenant(fn () => LimitRule::create(['role_id' => $this->roles->get('branch_manager')->id, 'key' => 'max_refund_amount', 'value' => '100000']));
        $sale = $this->sale(1);

        $first = $this->refund($sale['id'], 1);
        $this->postJson('/api/v1/pos/refunds', ['refunds' => [$first]], $this->tillHeaders($this->paired['token']))->assertOk()
            ->assertJsonPath('results.0.refund_status', 'applied')
            ->assertJsonPath('results.0.flags.0.code', 'override_offline');

        $this->getJson('/api/v1/pos/sales?flag=refund_flagged&reviewed=0', $this->headersFor())->assertOk()->assertJsonPath('data.0.id', $sale['id']);
        $this->getJson("/api/v1/pos/sales/{$sale['id']}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.flags.1.code', 'refund_flagged')
            ->assertJsonPath('data.flags.1.detail', ['flag' => 'override_offline', 'refund' => $first['id']])
            ->assertJsonPath('data.refunds.0.flags.0.code', 'override_offline');

        // Reviewed, then a second flagged refund re-opens the sale (audited).
        $this->postJson("/api/v1/pos/sales/{$sale['id']}/review", ['note' => 'Manager confirmed'], $this->headersFor())->assertOk();
        $second = $this->refund($sale['id'], 2);
        $this->postJson('/api/v1/pos/refunds', ['refunds' => [$second]], $this->tillHeaders($this->paired['token']))->assertOk();
        // A resend changes nothing.
        $this->postJson('/api/v1/pos/refunds', ['refunds' => [$second]], $this->tillHeaders($this->paired['token']))->assertOk();

        $this->inTenant(function () use ($sale, $first, $second) {
            $stored = Sale::query()->findOrFail($sale['id']);
            $this->assertNull($stored->reviewed_at);
            $this->assertSame([$first['id'], $second['id']], array_column(array_column($stored->flags, 'detail'), 'refund'));
            $entry = AuditEntry::query()->where('action', 'pos.sale.review_reopened')->sole();
            $this->assertSame(['refund_flagged', $sale['id']], [$entry->after['flag'], $entry->auditable_id]);
        });
    }

    public function test_a_void_with_an_offline_override_flags_its_sale_and_the_detail_shows_it(): void
    {
        $sale = $this->sale(1);
        $voidId = $this->id();
        $this->postJson('/api/v1/pos/voids', $this->void($sale['id'], $this->signed($voidId, now()->toIso8601String()), $voidId), $this->tillHeaders($this->paired['token']))->assertOk();

        $this->getJson("/api/v1/pos/sales/{$sale['id']}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.void.flags.0.code', 'override_offline')
            ->assertJsonPath('data.flags.1.code', 'void_flagged')
            ->assertJsonPath('data.flags.1.detail', ['flag' => 'override_offline', 'void' => $voidId]);
    }
}
