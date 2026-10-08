<?php

namespace Tests\Feature\Core\Sync;

use App\Core\Audit\AuditEntry;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Pin\OverrideAlreadyApplied;
use App\Core\Identity\Pin\OverrideVerifier;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTill;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-08 manager override: online tokens bound to a record, offline device
// signatures by key id within the secret's validity, replay protection,
// explicit "already applied", review flags; both users recorded (AUD-01).
class ManagerOverrideTest extends TestCase
{
    use BuildsTill, RefreshTenantDatabase;

    private const MANAGER_PIN = '482619';

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

        $this->putJson('/api/v1/me/pos-pin', ['password' => $this->password, 'pin' => '4826'], $this->headersFor($this->cashier))->assertOk();
        $this->putJson('/api/v1/me/pos-pin', ['password' => $this->password, 'pin' => self::MANAGER_PIN], $this->headersFor($this->manager))->assertOk();
    }

    private function requestOverride(User $manager, string $pin = self::MANAGER_PIN, array $extra = [])
    {
        return $this->postJson('/api/v1/pos/override', [
            'manager_user_id' => $manager->id,
            'pin' => $pin,
            'permission' => 'pos.sale.void',
            'cashier_user_id' => $this->cashier->id,
            'reference' => 'sale-1',
            ...$extra,
        ], $this->deviceHeaders($this->till));
    }

    private function redeem(array $override, string $permission = 'pos.sale.void', string $reference = 'sale-1', ?array $till = null)
    {
        return $this->inTenant(fn () => app(OverrideVerifier::class)->redeem(Device::findOrFail(($till ?? $this->till)['id']), $override, $permission, $reference));
    }

    private function refused(callable $fn, string $code): ApiException
    {
        try {
            $fn();
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);

            return $e;
        }

        $this->fail("Expected {$code}");
    }

    /** An offline override as the POS app signs it (message v2, with the key id). */
    private function offline(array $fields = [], ?array $till = null): array
    {
        $till ??= $this->till;
        $override = array_merge([
            'id' => (string) Str::uuid7(),
            'kid' => $till['kid'],
            'manager_user_id' => $this->manager->id,
            'cashier_user_id' => $this->cashier->id,
            'permission' => 'pos.sale.void',
            'reference' => 'sale-1',
            'authorised_at' => CarbonImmutable::now()->setTimezone('Africa/Nairobi')->toIso8601String(),
        ], $fields);

        $message = OverrideVerifier::offlineMessage($this->till['id'], $override['kid'], $override['id'], $override['manager_user_id'], $override['cashier_user_id'], $override['permission'], $override['reference'], $override['authorised_at']);
        $override['signature'] = $this->proof($till['secret'], $message);

        return $override;
    }

    public function test_a_manager_override_is_a_signed_short_lived_token_bound_to_the_device_and_record(): void
    {
        $this->requestOverride($this->manager, extra: ['reference' => null])->assertUnprocessable()->assertJsonValidationErrors('reference');

        $issued = $this->requestOverride($this->manager)->assertOk()->assertJsonPath('data.manager_user_id', $this->manager->id);
        $token = $issued->json('data.token');
        $this->assertMatchesRegularExpression('/^ovr1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token);

        $verified = $this->redeem(['token' => $token]);
        $this->assertSame($this->manager->id, $verified->managerUserId);
        $this->assertSame($this->cashier->id, $verified->cashierUserId);
        $this->assertSame('online', $verified->mode);
        $this->assertTrue($verified->managerHoldsPermission && $verified->managerActive && $verified->managerStaffAtLocation);
        $this->assertFalse($verified->needsReview());

        // The same record again (a re-upload) is an explicit outcome the caller handles; anything else is a replay.
        $again = $this->refused(fn () => $this->redeem(['token' => $token]), 'override_already_applied');
        $this->assertInstanceOf(OverrideAlreadyApplied::class, $again);
        $this->assertSame($verified->overrideId, $again->override->overrideId);
        $this->refused(fn () => $this->redeem(['token' => $token], reference: 'sale-2'), 'override_mismatch');
        $this->refused(fn () => $this->redeem(['token' => $token], reference: ' '), 'override_reference_required');

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
        $this->requestOverride($this->cashier, '4826')->assertForbidden()->assertJsonPath('code', 'override_not_permitted');
        $this->requestOverride($this->manager, '111111')->assertUnprocessable()->assertJsonPath('attempts_left', 4);
        $this->requestOverride($this->manager, extra: ['permission' => 'pos.nothing.here'])->assertUnprocessable()->assertJsonValidationErrors('permission');

        $elsewhere = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->putJson('/api/v1/me/pos-pin', ['password' => $this->password, 'pin' => self::MANAGER_PIN], $this->headersFor($elsewhere))->assertOk();
        $this->requestOverride($elsewhere)->assertUnprocessable()->assertJsonPath('code', 'not_staff_here');
    }

    public function test_an_offline_override_is_verified_by_its_device_signature_and_used_once(): void
    {
        $override = $this->offline();

        $verified = $this->redeem($override);
        $this->assertSame('offline', $verified->mode);
        $this->assertSame($this->manager->id, $verified->managerUserId);
        $this->assertSame($this->cashier->id, $verified->cashierUserId);
        $this->assertTrue($verified->managerHoldsPermission);
        $this->assertTrue($verified->needsReview(), 'offline overrides are device claims, reviewed');

        $this->refused(fn () => $this->redeem($override), 'override_already_applied');
        // The signature names the record; the same id signed again for another record is a replay.
        $this->refused(fn () => $this->redeem($override, reference: 'sale-2'), 'override_mismatch');
        $this->refused(fn () => $this->redeem($this->offline(['id' => $override['id'], 'reference' => 'sale-2']), reference: 'sale-2'), 'override_replayed');

        // Any change to a signed field breaks the signature.
        foreach (['manager_user_id' => $this->owner->id, 'kid' => 'unknown', 'authorised_at' => '2026-10-08T09:16:00+03:00'] as $field => $value) {
            $tampered = $override;
            $tampered[$field] = $value;
            $this->refused(fn () => $this->redeem($tampered), 'override_invalid');
        }

        // Signed with another device's secret, or for another action.
        $other = $this->pairTill($this->locationA, 'Other till');
        $this->refused(fn () => $this->redeem($this->offline(['kid' => $this->till['kid']], [...$other, 'kid' => $this->till['kid']])), 'override_invalid');
        $this->refused(fn () => $this->redeem($this->offline(), 'pos.sale.refund'), 'override_mismatch');
    }

    public function test_offline_overrides_must_be_dated_while_their_secret_was_current(): void
    {
        $this->refused(fn () => $this->redeem($this->offline(['authorised_at' => now()->subDay()->toIso8601String()])), 'override_invalid');
        $this->refused(fn () => $this->redeem($this->offline(['authorised_at' => now()->addMinutes(11)->toIso8601String()])), 'override_invalid');
        $this->assertSame('offline', $this->redeem($this->offline(['authorised_at' => now()->addMinutes(9)->toIso8601String()]))->mode);

        // Signed before a rotation, uploaded after it: still good under the retired key.
        $before = $this->offline(['reference' => 'sale-before']);
        $this->travel(5)->minutes();
        $rotated = $this->rotateTill($this->till);
        $this->assertSame('offline', $this->redeem($before, reference: 'sale-before')->mode);

        // The retired key cannot sign anything dated after it was retired.
        $this->travel(20)->minutes();
        $this->refused(fn () => $this->redeem($this->offline(['reference' => 'sale-late'])), 'override_invalid');
        $this->assertSame('offline', $this->redeem($this->offline(['reference' => 'sale-new'], $rotated), reference: 'sale-new')->mode);
    }

    public function test_redemption_flags_a_manager_who_left_lost_the_permission_or_is_no_longer_staff_here(): void
    {
        $verified = $this->redeem($this->offline(['manager_user_id' => $this->cashier->id]));
        $this->assertFalse($verified->managerHoldsPermission);
        $this->assertTrue($verified->managerStaffAtLocation);

        $this->inTenant(fn () => RoleAssignment::query()->where('user_id', $this->manager->id)->get()->each->delete());
        $gone = $this->redeem($this->offline(['reference' => 'sale-2']), reference: 'sale-2');
        $this->assertFalse($gone->managerStaffAtLocation);
        $this->assertTrue($gone->managerActive);

        $this->inTenant(fn () => $this->manager->forceFill(['status' => User::STATUS_DEACTIVATED])->saveQuietly());
        $this->assertFalse($this->redeem($this->offline(['reference' => 'sale-3']), reference: 'sale-3')->managerActive);
    }
}
