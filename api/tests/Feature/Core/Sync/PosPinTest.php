<?php

namespace Tests\Feature\Core\Sync;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Identity\Pin\DevicePinState;
use App\Core\Identity\Pin\UserPin;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\Scope;
use App\Core\Sync\DeviceSecrets;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTill;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-06 POS PIN and staff card, AUTH-07 staff list per till, RBAC-04..06
// in the staff entity, AUD-01 audit without secrets.
class PosPinTest extends TestCase
{
    use BuildsTill, RefreshTenantDatabase;

    private array $till;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerTillModule();
        $this->setUpOrganisation();
        $this->activateTill($this->owner->tenant_id);
        $this->till = $this->pairTill($this->locationA);
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
    }

    private function setPin(User $user, string $pin, array $extra = [])
    {
        return $this->putJson('/api/v1/me/pos-pin', ['password' => $this->password, 'pin' => $pin, ...$extra], $this->headersFor($user));
    }

    private function verify(User $user, string $pin, ?array $till = null)
    {
        return $this->postJson('/api/v1/pos/pin/verify', ['user_id' => $user->id, 'pin' => $pin], $this->deviceHeaders($till ?? $this->till));
    }

    /** The staff row of $user as $till sees it. */
    private function staffRow(User $user, ?array $till = null): ?array
    {
        $rows = $this->pull($till ?? $this->till, ['staff'])->assertOk()->json('entities.staff.upserts');

        return collect($rows)->firstWhere('id', $user->id);
    }

    /** What the POS app does offline: PBKDF2 of the PIN, then HMAC under the device secret. */
    private function deviceAccepts(array $material, string $userId, string $pin, string $secret, string $kind = 'pin'): bool
    {
        $key = hash_pbkdf2('sha256', $pin, DeviceSecrets::decode($material['salt']), $material['iterations'], 32, true);
        $expected = hash_hmac('sha256', "{$kind}:v1:{$userId}:".$key, DeviceSecrets::decode($secret), true);

        return hash_equals(DeviceSecrets::decode($material['verifier']), $expected);
    }

    public function test_users_set_their_own_pin_with_their_password_and_weak_pins_are_refused(): void
    {
        $this->putJson('/api/v1/me/pos-pin', ['password' => 'wrong-password-1', 'pin' => '4826'], $this->headersFor($this->cashier))
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_password');

        foreach (['1234', '0000', '987654', '1212', '123123', '2580', '12a4', '123', '1234567'] as $weak) {
            $this->setPin($this->cashier, $weak)->assertUnprocessable()->assertJsonValidationErrors('pin');
        }

        $this->setPin($this->cashier, '4826')->assertOk()->assertJsonPath('data.pin_set', true)->assertJsonPath('data.card_set', false);
        $this->getJson('/api/v1/me/pos-pin', $this->headersFor($this->cashier))->assertOk()
            ->assertJsonPath('data.pin_set', true)
            ->assertJsonMissingPath('data.pin');

        $this->inTenant(function () {
            $row = DB::table('user_pins')->where('user_id', $this->cashier->id)->first();
            $this->assertStringStartsWith('$argon2id$', $row->pin_hash);
            foreach ((array) $row as $column => $value) {
                $this->assertStringNotContainsString('4826', (string) $value, "user_pins.{$column} holds the PIN");
            }
            $record = UserPin::query()->where('user_id', $this->cashier->id)->sole();
            $this->assertGreaterThanOrEqual(100000, $record->pin_iterations);
            $this->assertSame(32, strlen(DeviceSecrets::decode($record->pin_key)));

            $entry = AuditEntry::query()->where('action', 'core.user.pin_set')->sole();
            $this->assertSame($this->cashier->id, $entry->auditable_id);
            $this->assertStringNotContainsString('argon2id', json_encode($entry->after));
            $this->assertEqualsCanonicalizing(['pin_set' => true, 'card_set' => false, 'version' => 1, 'must_change' => false], $entry->after);
        });
    }

    public function test_an_administrator_resets_or_removes_a_pin_but_never_reads_it(): void
    {
        $this->putJson("/api/v1/users/{$this->cashier->id}/pos-pin", ['pin' => '5937'], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.pin_set', true)
            ->assertJsonPath('data.must_change', true)
            ->assertJsonMissingPath('data.pin');

        // A PIN someone else chose is changed at the till first.
        $this->verify($this->cashier, '5937')->assertOk()->assertJsonPath('data.must_change', true);
        $this->assertTrue($this->staffRow($this->cashier)['must_change']);
        $this->postJson('/api/v1/pos/pin/change', ['user_id' => $this->cashier->id, 'pin' => '5937', 'new_pin' => '1234'], $this->deviceHeaders($this->till))
            ->assertUnprocessable()->assertJsonValidationErrors('new_pin');
        $this->postJson('/api/v1/pos/pin/change', ['user_id' => $this->cashier->id, 'pin' => '0000', 'new_pin' => '8051'], $this->deviceHeaders($this->till))
            ->assertUnprocessable()->assertJsonPath('code', 'pin_incorrect');
        $this->postJson('/api/v1/pos/pin/change', ['user_id' => $this->cashier->id, 'pin' => '5937', 'new_pin' => '8051'], $this->deviceHeaders($this->till))
            ->assertOk()->assertJsonPath('data.must_change', false);
        $this->assertFalse($this->staffRow($this->cashier)['must_change']);
        $this->verify($this->cashier, '8051')->assertOk();

        // An administrator acting on themselves confirms their password, and needs 6 digits (they approve overrides).
        $this->putJson("/api/v1/users/{$this->owner->id}/pos-pin", ['pin' => '593704'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson("/api/v1/users/{$this->owner->id}/pos-pin", ['pin' => '5937', 'password' => $this->password], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->putJson("/api/v1/users/{$this->owner->id}/pos-pin", ['pin' => '593704', 'password' => 'wrong-password-9'], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'invalid_password');
        $this->putJson("/api/v1/users/{$this->owner->id}/pos-pin", ['pin' => '593704', 'password' => $this->password], $this->headersFor())->assertOk()->assertJsonPath('data.must_change', false);

        // A cashier cannot reach the owner, nor reset anyone.
        $this->putJson("/api/v1/users/{$this->owner->id}/pos-pin", ['pin' => '5937'], $this->headersFor($this->cashier))->assertNotFound();

        $this->deleteJson("/api/v1/users/{$this->cashier->id}/pos-pin", [], $this->headersFor())->assertOk()->assertJsonPath('data.pin_set', false);
        $this->verify($this->cashier, '8051')->assertUnprocessable()->assertJsonPath('code', 'pin_not_set');

        $this->inTenant(fn () => $this->assertSame(
            ['core.user.pin_reset', 'core.user.pin_set', 'core.user.pin_set', 'core.user.pin_clear'],
            AuditEntry::query()->where('action', 'like', 'core.user.pin_%')->orderBy('seq')->pluck('action')->all(),
        ));
    }

    public function test_five_wrong_pins_lock_the_pin_on_that_device_until_it_is_reset(): void
    {
        $this->setPin($this->cashier, '4826')->assertOk();
        $other = $this->pairTill($this->locationA, 'Second till');

        foreach ([4, 3, 2, 1] as $left) {
            $this->verify($this->cashier, '1111')->assertUnprocessable()
                ->assertJsonPath('code', 'pin_incorrect')
                ->assertJsonPath('attempts_left', $left);
        }

        $this->verify($this->cashier, '1111')->assertStatus(423)->assertJsonPath('code', 'pin_locked');
        $this->verify($this->cashier, '4826')->assertStatus(423);
        $this->assertTrue($this->staffRow($this->cashier)['locked']);

        // Only on that device.
        $this->verify($this->cashier, '4826', $other)->assertOk()->assertJsonPath('data.user_id', $this->cashier->id);

        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::query()->where('action', 'core.user.pin_locked')->count()));

        // A new PIN clears the lockout.
        $this->setPin($this->cashier, '7391')->assertOk();
        $this->verify($this->cashier, '7391')->assertOk();
        $this->assertFalse($this->staffRow($this->cashier)['locked']);
    }

    public function test_a_right_pin_resets_the_count_of_wrong_ones(): void
    {
        $this->setPin($this->cashier, '4826')->assertOk();
        $this->verify($this->cashier, '1111')->assertJsonPath('attempts_left', 4);
        $this->verify($this->cashier, '4826')->assertOk();
        $this->verify($this->cashier, '1111')->assertJsonPath('attempts_left', 4);
    }

    public function test_only_staff_of_the_devices_location_sign_in_there(): void
    {
        $elsewhere = $this->userWith('cashier', Scope::location($this->locationB->id));
        $admin = $this->userWith('admin', Scope::tenant());
        $this->setPin($elsewhere, '4826')->assertOk();
        $this->setPin($admin, '4826')->assertOk();

        $this->verify($elsewhere, '4826')->assertUnprocessable()->assertJsonPath('code', 'not_staff_here');
        $this->verify($admin, '4826')->assertUnprocessable()->assertJsonPath('code', 'not_staff_here');

        $other = $this->otherTenant();
        $this->verify($other['user'], '4826')->assertUnprocessable()->assertJsonValidationErrors('user_id');

        // A role with till permissions but not the sign-in one does not make staff.
        $viewer = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Sales viewer', ['pos.sale.view']), Scope::location($this->locationA->id));

            return $user;
        });

        $staff = collect($this->pull($this->till, ['staff'])->assertOk()->json('entities.staff.upserts'))->pluck('id')->all();
        $this->assertContains($this->cashier->id, $staff);
        $this->assertContains($this->owner->id, $staff);
        $this->assertNotContains($elsewhere->id, $staff);
        $this->assertNotContains($admin->id, $staff);
        $this->assertNotContains($viewer->id, $staff);

        // A deactivated cashier leaves the list.
        $this->inTenant(fn () => $this->cashier->forceFill(['status' => User::STATUS_DEACTIVATED])->saveQuietly());
        $this->assertNull($this->staffRow($this->cashier));
    }

    public function test_the_staff_entity_carries_offline_pin_material_never_the_pin(): void
    {
        $this->setPin($this->cashier, '4826', ['card' => 'ab12cd34ef'])->assertOk()->assertJsonPath('data.card_set', true);
        $second = $this->pairTill($this->locationA, 'Second till');

        $response = $this->pull($this->till, ['staff'])->assertOk();
        $row = collect($response->json('entities.staff.upserts'))->firstWhere('id', $this->cashier->id);

        $this->assertSame(['id', 'name', 'permissions', 'limits', 'field_rules', 'offline', 'pin', 'card', 'pin_version', 'must_change', 'failed_attempts', 'locked'], array_keys($row));
        $this->assertSame('pbkdf2-sha256+hmac-sha256/v1', $row['pin']['scheme']);
        $this->assertSame($this->till['kid'], $row['pin']['kid']);
        $this->assertGreaterThanOrEqual(100000, $row['pin']['iterations']);
        $this->assertTrue($this->deviceAccepts($row['pin'], $this->cashier->id, '4826', $this->till['secret']));
        $this->assertFalse($this->deviceAccepts($row['pin'], $this->cashier->id, '4827', $this->till['secret']));
        $this->assertFalse($this->deviceAccepts($row['pin'], $this->owner->id, '4826', $this->till['secret']), 'the verifier is bound to its user');
        $this->assertTrue($this->deviceAccepts($row['card'], $this->cashier->id, 'AB12CD34EF', $this->till['secret'], 'card'));

        // Each device gets its own verifier, useless with another device's secret.
        $other = $this->staffRow($this->cashier, $second);
        $this->assertNotSame($row['pin']['verifier'], $other['pin']['verifier']);
        $this->assertFalse($this->deviceAccepts($row['pin'], $this->cashier->id, '4826', $second['secret']));
        $this->assertTrue($this->deviceAccepts($other['pin'], $this->cashier->id, '4826', $second['secret']));

        // Never a hash, the key or anything about the user beyond the till's needs.
        $body = $response->getContent();
        $this->assertStringNotContainsString('argon2id', $body);
        $key = $this->inTenant(fn () => UserPin::query()->where('user_id', $this->cashier->id)->sole()->pin_key);
        $this->assertStringNotContainsString($key, $body);
        $this->assertStringNotContainsString($this->cashier->email, $body);
        $this->assertStringNotContainsString('4826', $body);

        // Rotating the device secret changes the verifiers, once the new secret is active.
        $rotated = $this->rotateTill($this->till);
        $after = $this->staffRow($this->cashier);
        $this->assertSame($rotated['kid'], $after['pin']['kid']);
        $this->assertNotSame($row['pin']['verifier'], $after['pin']['verifier']);
        $this->assertTrue($this->deviceAccepts($after['pin'], $this->cashier->id, '4826', $rotated['secret']));
    }

    public function test_owners_by_template_sign_in_online_only_unless_they_have_a_role_where_they_work(): void
    {
        $this->setPin($this->owner, '593704')->assertOk();

        $row = $this->staffRow($this->owner);
        $this->assertFalse($row['offline']);
        $this->assertNull($row['pin']);
        $this->verify($this->owner, '593704')->assertOk();

        // A tenant-wide role that is not an Owner role grants it deliberately.
        $supervisor = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Roaming cashier', ['pos.till.sign_in', 'pos.sale.create']), Scope::tenant());

            return $user;
        });
        $this->setPin($supervisor, '4826')->assertOk();
        $this->assertNotNull($this->staffRow($supervisor)['pin']);

        // An Owner who also works here gets the material.
        $this->inTenant(fn () => $this->assign($this->owner, $this->roles->get('cashier'), Scope::location($this->locationA->id)));
        $this->assertNotNull($this->staffRow($this->owner)['pin']);
    }

    public function test_the_pin_status_says_whether_set_must_change_and_six_digits_never_the_pin(): void
    {
        $this->getJson("/api/v1/users/{$this->cashier->id}/pos-pin", $this->headersFor())->assertOk()
            ->assertJsonPath('data.pin_set', false)->assertJsonPath('data.six_digits', false);
        $this->putJson("/api/v1/users/{$this->cashier->id}/pos-pin", ['pin' => '5937'], $this->headersFor())->assertOk();
        $this->getJson("/api/v1/users/{$this->cashier->id}/pos-pin", $this->headersFor())->assertOk()
            ->assertJsonPath('data.pin_set', true)->assertJsonPath('data.must_change', true)->assertJsonMissingPath('data.pin');

        // The owner approves overrides: 6 digits, on their own status too.
        $this->getJson('/api/v1/me/pos-pin', $this->headersFor())->assertOk()->assertJsonPath('data.six_digits', true);
        $this->getJson('/api/v1/me/pos-pin', $this->headersFor($this->cashier))->assertOk()->assertJsonPath('data.six_digits', false);

        // RBAC-04: a cashier cannot read the owner's status.
        $this->getJson("/api/v1/users/{$this->owner->id}/pos-pin", $this->headersFor($this->cashier))->assertNotFound();
    }

    public function test_people_who_approve_overrides_need_six_digit_pins(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->setPin($manager, '4826')->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->setPin($manager, '482619')->assertOk();

        // A cashier with a 4-digit PIN who later gets an approving role must choose 6 digits.
        $this->setPin($this->cashier, '4826')->assertOk();
        $this->assertFalse($this->staffRow($this->cashier)['must_change']);
        $this->inTenant(fn () => $this->assign($this->cashier, $this->roles->get('branch_manager'), Scope::branch($this->branchA->id)));
        $this->assertTrue($this->staffRow($this->cashier)['must_change']);
        $this->postJson('/api/v1/pos/pin/change', ['user_id' => $this->cashier->id, 'pin' => '4826', 'new_pin' => '8051'], $this->deviceHeaders($this->till))
            ->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->postJson('/api/v1/pos/pin/change', ['user_id' => $this->cashier->id, 'pin' => '4826', 'new_pin' => '805193'], $this->deviceHeaders($this->till))->assertOk();
        $this->assertFalse($this->staffRow($this->cashier)['must_change']);
    }

    public function test_staff_rows_carry_till_permissions_limits_and_field_rules(): void
    {
        $this->inTenant(function () {
            $role = $this->roles->get('cashier');
            LimitRule::create(['role_id' => $role->id, 'key' => 'max_discount_percent', 'value' => '5']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'party', 'field' => 'credit_limit', 'mode' => 'hidden']);
        });

        $row = $this->staffRow($this->cashier);

        $this->assertContains('pos.sale.create', $row['permissions']);
        $this->assertNotContains('pos.sale.void', $row['permissions']);
        $this->assertSame([], array_filter($row['permissions'], fn ($p) => ! str_starts_with($p, 'pos.')));
        $this->assertSame(['max_discount_percent' => '5.0000'], $row['limits']);
        $this->assertSame(['hidden' => ['credit_limit'], 'readonly' => []], $row['field_rules']['party']);
        $this->assertNull($row['pin']);
        $this->assertContains('pos.sale.void', $this->staffRow($this->owner)['permissions']);
    }

    public function test_offline_attempt_reports_only_ever_raise_the_count_and_lock(): void
    {
        $this->setPin($this->cashier, '4826')->assertOk();
        $report = fn (int $failed, bool $locked) => $this->postJson('/api/v1/pos/pin/attempts', [
            'reports' => [['user_id' => $this->cashier->id, 'failed_attempts' => $failed, 'locked' => $locked, 'occurred_at' => now()->toIso8601String()]],
        ], $this->deviceHeaders($this->till));

        $report(3, false)->assertOk()->assertJsonPath('data.0.failed_attempts', 3)->assertJsonPath('data.0.locked', false);
        $report(2, false)->assertOk()->assertJsonPath('data.0.failed_attempts', 3);
        $report(3, false)->assertOk()->assertJsonPath('data.0.failed_attempts', 3);
        $this->verify($this->cashier, '1111')->assertJsonPath('attempts_left', 1);

        $report(5, true)->assertOk()->assertJsonPath('data.0.locked', true);
        $report(0, false)->assertOk()->assertJsonPath('data.0.locked', true);
        $report(7, true)->assertOk()->assertJsonPath('data.0.locked', true);
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::query()->where('action', 'core.user.pin_locked')->count(), 'one entry per lock'));
        $this->verify($this->cashier, '4826')->assertStatus(423);
        $this->assertTrue($this->staffRow($this->cashier)['locked']);

        $other = $this->otherTenant();
        $this->postJson('/api/v1/pos/pin/attempts', ['reports' => [['user_id' => $other['user']->id, 'failed_attempts' => 1, 'locked' => false]]], $this->deviceHeaders($this->till))
            ->assertUnprocessable();

        // Only staff of this till's location: others are skipped, the rest of the batch counts.
        $elsewhere = $this->userWith('cashier', Scope::location($this->locationB->id));
        $this->postJson('/api/v1/pos/pin/attempts', ['reports' => [
            ['user_id' => $elsewhere->id, 'failed_attempts' => 5, 'locked' => true],
            ['user_id' => $this->owner->id, 'failed_attempts' => 1, 'locked' => false],
        ]], $this->deviceHeaders($this->till))
            ->assertOk()
            ->assertJsonPath('data.0', ['user_id' => $elsewhere->id, 'skipped' => 'not_staff_here'])
            ->assertJsonPath('data.1.failed_attempts', 1);
        $this->inTenant(fn () => $this->assertSame(0, DevicePinState::query()->where('user_id', $elsewhere->id)->count()));
    }

    public function test_a_staff_card_signs_in_like_a_pin(): void
    {
        $this->setPin($this->cashier, '4826', ['card' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('card');
        $this->setPin($this->cashier, '4826', ['card' => 'ab12cd34ef'])->assertOk();

        $this->postJson('/api/v1/pos/pin/verify', ['user_id' => $this->cashier->id, 'card' => 'AB12CD34EF'], $this->deviceHeaders($this->till))->assertOk();
        $this->postJson('/api/v1/pos/pin/verify', ['user_id' => $this->cashier->id, 'card' => 'ZZ12CD34EF'], $this->deviceHeaders($this->till))
            ->assertUnprocessable()->assertJsonPath('code', 'pin_incorrect');
        $this->postJson('/api/v1/pos/pin/verify', ['user_id' => $this->cashier->id, 'card' => 'AB12CD34EF', 'pin' => '4826'], $this->deviceHeaders($this->till))
            ->assertUnprocessable();

        // Null removes the card, absent keeps it.
        $this->setPin($this->cashier, '7391')->assertOk()->assertJsonPath('data.card_set', true);
        $this->setPin($this->cashier, '7391', ['card' => null])->assertOk()->assertJsonPath('data.card_set', false);
    }
}
