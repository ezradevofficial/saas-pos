<?php

namespace Tests\Feature\Core\Sync;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Identity\Pin\ActorProofResult;
use App\Core\Identity\Pin\ActorProofVerifier;
use App\Core\Identity\Pin\TillSignIn;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTill;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-07 till sign-in attestation (actor proof): the device signs
// "signin:v1\n{device}\n{kid}\n{session}\n{user}\n{signed_in_at}" with its
// secret; core verifies key, signature, key window, user and staff rules,
// and whether the server checked that sign-in online (pos/pin/verify with
// a session_id, recorded once and audited, AUD-01).
class ActorProofTest extends TestCase
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
        $this->putJson('/api/v1/me/pos-pin', ['password' => $this->password, 'pin' => '4826'], $this->headersFor($this->cashier))->assertOk();
    }

    /** A sign-in attestation as the POS app makes it; $tamper changes fields after signing. */
    private function attest(array $fields = [], ?array $till = null, array $tamper = []): array
    {
        $till ??= $this->till;
        $proof = array_merge([
            'session_id' => (string) Str::uuid7(),
            'user_id' => $this->cashier->id,
            'signed_in_at' => CarbonImmutable::now()->format('Y-m-d\TH:i:s.v\Z'),
            'kid' => $till['kid'],
        ], $fields);
        $proof['signature'] = $this->proof($till['secret'], ActorProofVerifier::message($till['id'], $proof['kid'], $proof['session_id'], $proof['user_id'], $proof['signed_in_at']));

        return array_merge($proof, $tamper);
    }

    private function check(array $proof, ?string $expected = null, ?array $till = null): ActorProofResult
    {
        return $this->inTenant(fn () => app(ActorProofVerifier::class)->verify(Device::findOrFail(($till ?? $this->till)['id']), $proof, $expected ?? $this->cashier->id));
    }

    private function failsWith(string $reason, array $proof, ?string $expected = null, ?array $till = null): void
    {
        $result = $this->check($proof, $expected, $till);
        $this->assertFalse($result->ok());
        $this->assertSame($reason, $result->reason);
    }

    public function test_the_signature_matches_the_fixed_vector_the_app_mirrors(): void
    {
        // Shared with the POS app's tests: secret = bytes 0x00..0x1f.
        $secret = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';
        $message = ActorProofVerifier::message(
            '01a11ea7-0000-7000-8000-000000000001',
            '0123456789abcdef',
            '01a11ea7-0000-7000-8000-000000000002',
            '01a11ea7-0000-7000-8000-000000000003',
            '2026-10-09T07:58:00.000Z',
        );

        $this->assertSame("signin:v1\n01a11ea7-0000-7000-8000-000000000001\n0123456789abcdef\n01a11ea7-0000-7000-8000-000000000002\n01a11ea7-0000-7000-8000-000000000003\n2026-10-09T07:58:00.000Z", $message);
        $this->assertSame('z249kNXKbGhVX6QUmw6N1z21fWwV9Y_T-8FtiNxjt5g', $this->proof($secret, $message));
    }

    public function test_a_signed_sign_in_verifies_as_the_devices_claim_until_the_server_checked_it(): void
    {
        $proof = $this->attest(['signed_in_at' => CarbonImmutable::now()->setTimezone('Africa/Nairobi')->toIso8601String()]);
        $result = $this->check($proof);

        $this->assertTrue($result->ok());
        $this->assertSame($this->cashier->id, $result->actor->user->id);
        $this->assertSame($proof['session_id'], $result->actor->sessionId);
        $this->assertFalse($result->actor->online, 'signed on the device only');
        $this->assertTrue($result->actor->signedInAt->isUtc());

        // The same session checked online through pos/pin/verify: online.
        $online = $this->attest();
        $this->postJson('/api/v1/pos/pin/verify', [
            'user_id' => $this->cashier->id, 'pin' => '4826', 'session_id' => $online['session_id'], 'signed_in_at' => $online['signed_in_at'],
        ], $this->deviceHeaders($this->till))->assertOk()->assertJsonPath('data.session_id', $online['session_id']);
        $this->assertTrue($this->check($online)->actor->online);

        // The recorded session pins its sign-in time: the same session with another time does not verify.
        $moved = $this->attest(['session_id' => $online['session_id'], 'signed_in_at' => CarbonImmutable::parse($online['signed_in_at'])->subMinute()->format('Y-m-d\TH:i:s.v\Z')]);
        $this->failsWith(ActorProofVerifier::FAIL_SESSION_MISMATCH, $moved);

        // Recorded for this device only: the same session id from another till is that till's claim.
        $other = $this->pairTill($this->locationA, 'Other till');
        $this->assertFalse($this->check($this->attest(['session_id' => $online['session_id']], $other), till: $other)->actor->online);
    }

    public function test_unknown_keys_bad_signatures_and_malformed_fields_do_not_verify(): void
    {
        $proof = $this->attest();

        $this->failsWith(ActorProofVerifier::FAIL_UNKNOWN_KEY, $this->attest(['kid' => 'feedfacefeedface']));
        $this->failsWith(ActorProofVerifier::FAIL_SIGNATURE, $this->attest(tamper: ['signature' => $this->attest()['signature']]));
        $this->failsWith(ActorProofVerifier::FAIL_SIGNATURE, [...$proof, 'session_id' => (string) Str::uuid7()]);
        $this->failsWith(ActorProofVerifier::FAIL_SIGNATURE, [...$proof, 'signed_in_at' => CarbonImmutable::now()->addSecond()->format('Y-m-d\TH:i:s.v\Z')]);
        $this->failsWith(ActorProofVerifier::FAIL_SIGNATURE, [...$proof, 'signature' => 'not-a-signature']);

        // Signed with another device's secret under this device's kid.
        $other = $this->pairTill($this->locationA, 'Other till');
        $this->failsWith(ActorProofVerifier::FAIL_SIGNATURE, $this->attest(['kid' => $this->till['kid']], [...$other, 'id' => $this->till['id']]));
        // A proof of this device presented as another's.
        $this->failsWith(ActorProofVerifier::FAIL_UNKNOWN_KEY, $proof, till: $other);

        foreach ([
            ['session_id' => 'not-a-uuid'],
            ['signed_in_at' => '2026-10-09 07:58'],
            ['signed_in_at' => '2026-10-09T07:58:00'],
            ['signed_in_at' => CarbonImmutable::now()->format('Y-m-d\TH:i:s.v\Z')."\n"],
            ['kid' => "{$this->till['kid']}\nx"],
        ] as $fields) {
            $this->failsWith(ActorProofVerifier::FAIL_MALFORMED, $this->attest($fields));
        }

        $this->failsWith(ActorProofVerifier::FAIL_MALFORMED, $this->attest(tamper: ['user_id' => null]));
        $this->failsWith(ActorProofVerifier::FAIL_MALFORMED, $this->attest(tamper: ['signature' => '']));
    }

    public function test_the_sign_in_must_be_dated_while_its_key_was_current(): void
    {
        $this->failsWith(ActorProofVerifier::FAIL_OUTSIDE_WINDOW, $this->attest(['signed_in_at' => now()->subDay()->format('Y-m-d\TH:i:s.v\Z')]));
        $this->failsWith(ActorProofVerifier::FAIL_OUTSIDE_WINDOW, $this->attest(['signed_in_at' => now()->addMinutes(11)->format('Y-m-d\TH:i:s.v\Z')]));
        $this->assertTrue($this->check($this->attest(['signed_in_at' => now()->addMinutes(9)->format('Y-m-d\TH:i:s.v\Z')]))->ok());

        // Signed before a rotation and uploaded after it: still good under the retired key, never dated after it.
        $before = $this->attest();
        $this->travel(5)->minutes();
        $rotated = $this->rotateTill($this->till);
        $this->assertTrue($this->check($before)->ok());
        $this->travel(20)->minutes();
        $this->failsWith(ActorProofVerifier::FAIL_OUTSIDE_WINDOW, $this->attest());
        $this->assertTrue($this->check($this->attest(till: $rotated))->ok());
    }

    public function test_the_user_must_be_the_records_actor_active_and_staff_here(): void
    {
        $this->failsWith(ActorProofVerifier::FAIL_WRONG_USER, $this->attest(), $this->owner->id);
        $unknown = (string) Str::uuid7();
        $this->failsWith(ActorProofVerifier::FAIL_USER_UNKNOWN, $this->attest(['user_id' => $unknown]), $unknown);

        $elsewhere = $this->userWith('cashier', Scope::location($this->locationB->id));
        $this->failsWith(ActorProofVerifier::FAIL_NOT_STAFF, $this->attest(['user_id' => $elsewhere->id]), $elsewhere->id);

        $this->inTenant(fn () => RoleAssignment::query()->where('user_id', $this->cashier->id)->get()->each->delete());
        $this->failsWith(ActorProofVerifier::FAIL_NOT_STAFF, $this->attest());

        $this->inTenant(fn () => $this->cashier->forceFill(['status' => User::STATUS_DEACTIVATED])->saveQuietly());
        $this->failsWith(ActorProofVerifier::FAIL_USER_INACTIVE, $this->attest());
    }

    public function test_pin_verify_records_the_session_once_audited_and_only_for_a_right_pin(): void
    {
        $session = (string) Str::uuid7();
        $verify = fn (string $pin, ?User $user = null, array $extra = []) => $this->postJson('/api/v1/pos/pin/verify', [
            'user_id' => ($user ?? $this->cashier)->id, 'pin' => $pin, 'session_id' => $session, 'signed_in_at' => '2026-10-09T07:58:00.000Z', ...$extra,
        ], $this->deviceHeaders($this->till));

        $verify('1111')->assertUnprocessable();
        $this->inTenant(fn () => $this->assertSame(0, TillSignIn::query()->count()));

        $verify('4826')->assertOk()->assertJsonPath('data.session_id', $session);
        $verify('4826')->assertOk()->assertJsonPath('data.session_id', $session);

        $this->inTenant(function () use ($session) {
            $row = TillSignIn::query()->sole();
            $this->assertSame([$this->till['id'], $this->cashier->id, $session, '2026-10-09T07:58:00.000Z'], [$row->device_id, $row->user_id, $row->session_id, $row->signed_in_at]);
            $entry = AuditEntry::query()->where('action', 'core.user.till_sign_in')->sole();
            $this->assertSame($this->cashier->id, $entry->auditable_id);
            $this->assertSame($session, $entry->after['session_id']);
        });

        // The same session for someone else on this device: refused (after their PIN is checked).
        $this->putJson('/api/v1/me/pos-pin', ['password' => $this->password, 'pin' => '482619'], $this->headersFor($this->owner))->assertOk();
        $verify('482619', $this->owner)->assertStatus(409)->assertJsonPath('code', 'till_session_conflict');

        // Without a session id nothing is recorded; a malformed one is refused.
        $this->postJson('/api/v1/pos/pin/verify', ['user_id' => $this->cashier->id, 'pin' => '4826'], $this->deviceHeaders($this->till))
            ->assertOk()->assertJsonPath('data.session_id', null);
        $verify('4826', extra: ['session_id' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('session_id');
        $verify('4826', extra: ['signed_in_at' => 'yesterday'])->assertUnprocessable()->assertJsonValidationErrors('signed_in_at');
        $this->inTenant(fn () => $this->assertSame(1, TillSignIn::query()->count()));
    }
}
