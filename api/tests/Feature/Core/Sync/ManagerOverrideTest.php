<?php

namespace Tests\Feature\Core\Sync;

use App\Core\Audit\AuditEntry;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Pin\OverrideVerifier;
use App\Core\Rbac\Scope;
use App\Core\Sync\DeviceSecrets;
use App\Core\Tenancy\Models\Device;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTill;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-08 manager override: online tokens, offline device signatures,
// binding, expiry and replay protection; both users recorded (AUD-01).
class ManagerOverrideTest extends TestCase
{
    use BuildsTill, RefreshTenantDatabase;

    private array $till;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerTillModule();
        $this->setUpOrganisation();
        $this->activateTill($this->owner->tenant_id);
        $this->till = $this->pairTill($this->locationA);
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        foreach ([$this->cashier, $this->manager] as $user) {
            $this->putJson('/api/v1/me/pos-pin', ['password' => $this->password, 'pin' => '4826'], $this->headersFor($user))->assertOk();
        }
    }

    private function requestOverride(User $manager, string $pin = '4826', array $extra = [])
    {
        return $this->postJson('/api/v1/pos/override', [
            'manager_user_id' => $manager->id,
            'pin' => $pin,
            'permission' => 'pos.sale.void',
            'cashier_user_id' => $this->cashier->id,
            ...$extra,
        ], $this->deviceHeaders($this->till));
    }

    private function redeem(array $override, string $permission = 'pos.sale.void', ?string $reference = 'sale-1', ?array $till = null)
    {
        return $this->inTenant(fn () => app(OverrideVerifier::class)->redeem(Device::findOrFail(($till ?? $this->till)['id']), $override, $permission, $reference));
    }

    private function refused(callable $fn, string $code): void
    {
        try {
            $fn();
            $this->fail("Expected {$code}");
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    /** An offline override as the POS app signs it. */
    private function offline(array $fields = [], ?string $secret = null): array
    {
        $override = array_merge([
            'id' => (string) Str::uuid7(),
            'manager_user_id' => $this->manager->id,
            'cashier_user_id' => $this->cashier->id,
            'permission' => 'pos.sale.void',
            'reference' => 'sale-1',
            'authorised_at' => '2026-10-08T09:15:00+03:00',
        ], $fields);

        $message = OverrideVerifier::offlineMessage($this->till['id'], $override['id'], $override['manager_user_id'], $override['cashier_user_id'], $override['permission'], $override['reference'], $override['authorised_at']);
        $override['signature'] = DeviceSecrets::encode(hash_hmac('sha256', $message, DeviceSecrets::decode($secret ?? $this->till['secret']), true));

        return $override;
    }

    public function test_a_manager_override_is_a_signed_short_lived_token_bound_to_the_device_and_action(): void
    {
        $issued = $this->requestOverride($this->manager)->assertOk()->assertJsonPath('data.manager_user_id', $this->manager->id);
        $token = $issued->json('data.token');
        $this->assertMatchesRegularExpression('/^ovr1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token);

        $verified = $this->redeem(['token' => $token]);
        $this->assertSame($this->manager->id, $verified->managerUserId);
        $this->assertSame($this->cashier->id, $verified->cashierUserId);
        $this->assertSame('online', $verified->mode);
        $this->assertTrue($verified->managerHoldsPermission);
        $this->assertTrue($verified->firstUse);

        // The same record again (a re-upload) answers the same; anything else is a replay.
        $this->assertFalse($this->redeem(['token' => $token])->firstUse);
        $this->refused(fn () => $this->redeem(['token' => $token], reference: 'sale-2'), 'override_replayed');

        $this->inTenant(function () {
            $entry = AuditEntry::query()->where('action', 'core.user.override_redeem')->sole();
            $this->assertSame($this->manager->id, $entry->after['manager_user_id']);
            $this->assertSame($this->cashier->id, $entry->after['cashier_user_id']);
            $this->assertSame('sale-1', $entry->after['reference']);
            $this->assertSame(1, AuditEntry::query()->where('action', 'core.user.override_issue')->count());
        });
    }

    public function test_tokens_are_refused_when_tampered_expired_for_another_action_or_another_device(): void
    {
        $token = $this->requestOverride($this->manager, extra: ['reference' => 'sale-9'])->assertOk()->json('data.token');

        [$prefix, $payload, $signature] = explode('.', $token);
        $forged = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $forged['perm'] = 'pos.sale.refund';
        $forgedToken = $prefix.'.'.rtrim(strtr(base64_encode(json_encode($forged)), '+/', '-_'), '=').'.'.$signature;

        $this->refused(fn () => $this->redeem(['token' => $forgedToken], 'pos.sale.refund', 'sale-9'), 'override_invalid');
        $this->refused(fn () => $this->redeem(['token' => $token], 'pos.sale.refund', 'sale-9'), 'override_mismatch');
        $this->refused(fn () => $this->redeem(['token' => $token], 'pos.sale.void', 'sale-1'), 'override_mismatch');
        $this->refused(fn () => $this->redeem(['token' => $token], 'pos.sale.void', 'sale-9', $this->pairTill($this->locationA, 'Other till')), 'override_invalid');

        $this->travel(3)->minutes();
        $this->refused(fn () => $this->redeem(['token' => $token], 'pos.sale.void', 'sale-9'), 'override_expired');
    }

    public function test_only_a_manager_holding_the_permission_here_gets_a_token_and_wrong_pins_count(): void
    {
        $this->requestOverride($this->cashier)->assertForbidden()->assertJsonPath('code', 'override_not_permitted');
        $this->requestOverride($this->manager, '1111')->assertUnprocessable()->assertJsonPath('attempts_left', 4);
        $this->requestOverride($this->manager, extra: ['permission' => 'pos.nothing.here'])->assertUnprocessable()->assertJsonValidationErrors('permission');

        $elsewhere = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->putJson('/api/v1/me/pos-pin', ['password' => $this->password, 'pin' => '4826'], $this->headersFor($elsewhere))->assertOk();
        $this->requestOverride($elsewhere)->assertUnprocessable()->assertJsonPath('code', 'not_staff_here');
    }

    public function test_an_offline_override_is_verified_by_its_device_signature_and_used_once(): void
    {
        $override = $this->offline();

        $verified = $this->redeem($override);
        $this->assertSame('offline', $verified->mode);
        $this->assertSame($this->manager->id, $verified->managerUserId);
        $this->assertSame($this->cashier->id, $verified->cashierUserId);
        $this->assertSame('2026-10-08T06:15:00+00:00', $verified->authorisedAt->utc()->toIso8601String());
        $this->assertTrue($verified->managerHoldsPermission);

        $this->assertFalse($this->redeem($override)->firstUse);
        // The signature names the record; the same id signed again for another record is a replay.
        $this->refused(fn () => $this->redeem($override, reference: 'sale-2'), 'override_mismatch');
        $this->refused(fn () => $this->redeem($this->offline(['id' => $override['id'], 'reference' => 'sale-2']), reference: 'sale-2'), 'override_replayed');

        // Any change to a signed field breaks the signature.
        $tampered = $override;
        $tampered['manager_user_id'] = $this->owner->id;
        $this->refused(fn () => $this->redeem($tampered), 'override_invalid');

        // Signed with another device's secret, or for another action.
        $other = $this->pairTill($this->locationA, 'Other till');
        $this->refused(fn () => $this->redeem($this->offline([], $other['secret'])), 'override_invalid');
        $this->refused(fn () => $this->redeem($this->offline(), 'pos.sale.refund'), 'override_mismatch');
    }

    public function test_offline_overrides_record_whether_the_manager_still_holds_the_permission_and_die_with_the_secret(): void
    {
        $verified = $this->redeem($this->offline(['manager_user_id' => $this->cashier->id]));
        $this->assertFalse($verified->managerHoldsPermission, 'kept as evidence, flagged for review');

        $pending = $this->offline();
        $this->postJson('/api/v1/sync/device-secret', [], $this->deviceHeaders($this->till))->assertOk();
        $this->refused(fn () => $this->redeem($pending), 'override_invalid');
    }
}
