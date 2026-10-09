<?php

namespace Modules\POS\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\Scope;
use Modules\POS\Models\Sale;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-07, POS-05, POS-09, RBAC-06: the till's signed sign-in attestation
// (`actor_proof`) proves who acted. A verified actor holding the permission
// within their limit has voids, refunds and pay-outs applied, flagged
// `actor_offline` when only the device checked the sign-in; a missing,
// unverifiable or someone else's proof keeps money out held and money in
// flagged `actor_unverified`; a malformed proof is a validation error.
class ActorProofUploadsTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private string $shift;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();

        $this->setUpPos();
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->ranges()->assertOk();
        $this->ranges('pos.refund')->assertOk();
        $this->shift = $this->openShift();
    }

    private function sale(int $seq): array
    {
        $sale = $this->saleBody($this->shift, $seq, ['cashier_id' => $this->cashier->id]);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.flags', []);

        return $sale;
    }

    private function void(array $sale, mixed $proof, ?User $by = null)
    {
        return $this->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $this->id(), 'sale_id' => $sale['id'], 'voided_by_id' => ($by ?? $this->manager)->id, 'actor_proof' => $proof,
            'voided_at' => now()->toIso8601String(), 'reason' => 'Wrong item', 'override' => null,
        ]]], $this->tillHeaders());
    }

    private function refund(array $sale, int $seq, ?array $proof)
    {
        return $this->postJson('/api/v1/pos/refunds', ['refunds' => [[
            'id' => $this->id(), 'sale_id' => $sale['id'], 'shift_id' => $this->shift, 'cashier_id' => $this->manager->id, 'actor_proof' => $proof,
            'receipt_seq' => $seq, 'receipt_number' => sprintf('RF-L01-%06d', $seq), 'refunded_at' => now()->toIso8601String(),
            'reason' => 'Damaged', 'total_minor' => '56250',
            'lines' => [['id' => $this->id(), 'sale_line_id' => $sale['lines'][0]['id'], 'qty' => '1']],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => '56250', 'amount_in_sale_minor' => '56250']],
        ]]], $this->tillHeaders());
    }

    private function movement(string $kind, ?array $proof)
    {
        return $this->postJson('/api/v1/pos/cash-movements', ['movements' => [[
            'id' => $this->id(), 'shift_id' => $this->shift, 'user_id' => $this->manager->id, 'kind' => $kind, 'currency' => 'KES',
            'amount_minor' => '20000', 'reason' => 'Supplies', 'occurred_at' => now()->toIso8601String(), 'actor_proof' => $proof,
        ]]], $this->tillHeaders());
    }

    private function codes($response): array
    {
        return array_column($response->json('results.0.flags'), 'code');
    }

    public function test_a_void_by_a_verified_holder_of_the_permission_is_applied_and_flagged_when_signed_offline(): void
    {
        // Signed on the device only: applied, flagged for review.
        $offline = $this->void($this->sale(1), $this->actorProof($this->manager->id))->assertOk()->assertJsonPath('results.0.void_status', 'applied');
        $this->assertSame(['actor_offline'], $this->codes($offline));

        // The sign-in was checked online (pos/pin/verify with its session): applied, nothing to review.
        $online = $this->void($this->sale(2), $this->actorProof($this->manager->id, online: true))->assertOk()->assertJsonPath('results.0.void_status', 'applied');
        $this->assertSame([], $this->codes($online));

        // A cashier who was given the permission is proven the same way.
        $this->inTenant(fn () => $this->roles->get('cashier')->givePermissionTo('pos.sale.void'));
        $this->void($this->sale(3), $this->actorProof($this->cashier->id, online: true), $this->cashier)->assertOk()->assertJsonPath('results.0.void_status', 'applied');

        $this->inTenant(fn () => $this->assertSame(3, Sale::query()->where('status', Sale::VOIDED)->count()));
    }

    public function test_a_void_without_a_proof_that_verifies_for_its_actor_is_held(): void
    {
        $cases = [
            'none' => null,
            'someone else' => $this->actorProof($this->cashier->id, online: true),
            'tampered' => $this->actorProof($this->manager->id, tamper: ['session_id' => $this->id()]),
            'unknown key' => $this->actorProof($this->manager->id, fields: ['kid' => 'feedfacefeedface']),
            'outside the key window' => $this->actorProof($this->manager->id, fields: ['signed_in_at' => now()->subDay()->format('Y-m-d\TH:i:s.v\Z')]),
        ];

        foreach (array_values($cases) as $index => $proof) {
            $held = $this->void($this->sale($index + 1), $proof)->assertOk()->assertJsonPath('results.0.void_status', 'held');
            $this->assertSame(['actor_unverified'], $this->codes($held), array_keys($cases)[$index]);
        }

        // Another till's proof (its own secret) does not prove anything here.
        [$other] = $this->pairedTill($this->locationA, 'Till 2');
        $this->void($this->sale(6), $this->actorProof($this->manager->id, $other))->assertOk()->assertJsonPath('results.0.void_status', 'held');
    }

    public function test_refunds_by_a_verified_actor_are_applied_within_their_limit_only(): void
    {
        // RBAC-06: KES 500.00 is below the KES 562.50 refund: refused as before, proof or not.
        $limit = $this->inTenant(fn () => LimitRule::create(['role_id' => $this->roles->get('branch_manager')->id, 'key' => 'max_refund_amount', 'value' => '500']));
        $sale = $this->sale(1);
        $this->refund($sale, 1, $this->actorProof($this->manager->id))->assertUnprocessable()->assertJsonPath('results.0.error.code', 'limit_exceeded');

        $this->inTenant(fn () => $limit->forceFill(['value' => '1000'])->save());
        $offline = $this->refund($sale, 1, $this->actorProof($this->manager->id))->assertOk()->assertJsonPath('results.0.refund_status', 'applied');
        $this->assertSame(['actor_offline'], $this->codes($offline));

        $this->refund($sale, 2, $this->actorProof($this->manager->id, online: true))->assertOk()
            ->assertJsonPath('results.0.refund_status', 'applied')->assertJsonPath('results.0.flags', []);
        $this->refund($this->sale(2), 3, null)->assertOk()->assertJsonPath('results.0.refund_status', 'held')->assertJsonPath('results.0.flags.0.code', 'actor_unverified');
    }

    public function test_pay_outs_follow_the_proof_and_pay_ins_are_never_flagged_offline(): void
    {
        $out = $this->movement('pay_out', $this->actorProof($this->manager->id))->assertOk()->assertJsonPath('results.0.movement_status', 'applied');
        $this->assertSame(['actor_offline'], $this->codes($out));
        $this->movement('pay_out', $this->actorProof($this->manager->id, online: true))->assertOk()
            ->assertJsonPath('results.0.movement_status', 'applied')->assertJsonPath('results.0.flags', []);
        $this->movement('pay_out', null)->assertOk()->assertJsonPath('results.0.movement_status', 'held')->assertJsonPath('results.0.flags.0.code', 'actor_unverified');

        // Money in: an offline-signed sign-in is enough; an unproven one is kept and flagged.
        $this->movement('pay_in', $this->actorProof($this->manager->id))->assertOk()
            ->assertJsonPath('results.0.movement_status', 'applied')->assertJsonPath('results.0.flags', []);
        $this->movement('pay_in', null)->assertOk()
            ->assertJsonPath('results.0.movement_status', 'applied')->assertJsonPath('results.0.flags.0.code', 'actor_unverified');
    }

    public function test_a_sale_is_flagged_only_when_its_cashier_is_not_proven(): void
    {
        $proven = $this->saleBody($this->shift, 1, ['cashier_id' => $this->cashier->id, 'actor_proof' => $this->actorProof($this->cashier->id)]);
        $this->upload([$proven])->assertOk()->assertJsonPath('results.0.flags', []);

        $someoneElse = $this->saleBody($this->shift, 2, ['cashier_id' => $this->cashier->id, 'actor_proof' => $this->actorProof($this->manager->id)]);
        $this->assertSame(['actor_unverified'], $this->codes($this->upload([$someoneElse])->assertOk()));
    }

    public function test_records_dated_before_the_sign_in_or_a_day_after_it_are_flagged_for_review(): void
    {
        // Device clocks drift: a sale dated before the sign-in it cites is kept and flagged, never refused.
        $early = $this->saleBody($this->shift, 1, ['cashier_id' => $this->cashier->id, 'actor_proof' => $this->actorProof($this->cashier->id, fields: ['signed_in_at' => now()->addMinutes(3)->format('Y-m-d\TH:i:s.v\Z')])]);
        $this->assertSame(['before_sign_in'], $this->codes($this->upload([$early])->assertOk()));

        // A proof is not bound to one record: a sign-in older than 24 hours is reviewed.
        $proof = $this->actorProof($this->manager->id);
        $sale = $this->sale(2);
        $this->travel(25)->hours();
        $void = $this->void($sale, $proof)->assertOk()->assertJsonPath('results.0.void_status', 'applied');
        $this->assertSame(['actor_offline', 'session_stale'], $this->codes($void));

        $fresh = $this->saleBody($this->shift, 3, ['cashier_id' => $this->cashier->id, 'sold_at' => now()->toIso8601String(), 'actor_proof' => $this->actorProof($this->cashier->id, fields: ['signed_in_at' => now()->subHours(23)->format('Y-m-d\TH:i:s.v\Z')])]);
        $this->assertSame([], $this->codes($this->upload([$fresh])->assertOk()));
    }

    public function test_a_malformed_proof_is_a_validation_error(): void
    {
        $sale = $this->sale(1);

        $proof = $this->actorProof($this->manager->id);
        $prefix = 'voids.0.actor_proof';

        foreach ([
            [$prefix, 'attested'],
            [$prefix, [...$proof, 'extra' => 'field']],
            ["{$prefix}.signature", ['session_id' => $this->id()]],
            ["{$prefix}.session_id", [...$proof, 'session_id' => 'not-a-uuid']],
            ["{$prefix}.signed_in_at", [...$proof, 'signed_in_at' => '2026-10-09 07:58']],
            ["{$prefix}.signature", [...$proof, 'signature' => 'not base64url']],
            ["{$prefix}.kid", [...$proof, 'kid' => "abc\ndef"]],
        ] as [$error, $bad]) {
            $this->void($sale, $bad)->assertUnprocessable()->assertJsonValidationErrors($error);
        }

        $this->postJson('/api/v1/pos/shifts', ['shifts' => [[...$this->shiftBody(), 'closing' => ['closed_by_id' => $this->owner->id, 'closed_at' => now()->toIso8601String(), 'actor_proof' => 'attested']]]], $this->tillHeaders())
            ->assertUnprocessable()->assertJsonValidationErrors('shifts.0.closing.actor_proof');
    }

    public function test_shift_opening_and_closing_record_whether_the_actor_was_proven(): void
    {
        $this->postJson('/api/v1/pos/shifts', ['shifts' => [[
            ...$this->shiftBody(['id' => $this->shift]),
            'closing' => ['closed_by_id' => $this->owner->id, 'closed_at' => now()->toIso8601String(), 'counted' => [], 'actor_proof' => $this->actorProof($this->owner->id, online: true)],
        ]]], $this->tillHeaders())->assertOk()->assertJsonPath('results.0.shift_status', 'closed');

        $opened = $this->shiftBody(['actor_proof' => $this->actorProof($this->owner->id)]);
        $this->postJson('/api/v1/pos/shifts', ['shifts' => [$opened]], $this->tillHeaders())->assertOk();

        $this->inTenant(function () use ($opened) {
            $close = AuditEntry::query()->where('action', 'pos.shift.close')->where('auditable_id', $this->shift)->sole();
            $this->assertSame([true, true], [$close->after['actor_verified'], $close->after['actor_online']]);
            $open = AuditEntry::query()->where('action', 'pos.shift.open')->where('auditable_id', $opened['id'])->sole();
            $this->assertSame([true, false], [$open->after['actor_verified'], $open->after['actor_online']]);
            // shiftBody opens an hour back, before this sign-in: reviewed, not refused (AUTH-07).
            $this->assertSame(['before_sign_in'], $open->after['actor_flags']);
            $this->assertSame([], $close->after['actor_flags']);
            // The first shift was opened (openShift) without a proof.
            $first = AuditEntry::query()->where('action', 'pos.shift.open')->where('auditable_id', $this->shift)->sole();
            $this->assertSame([false, null], [$first->after['actor_verified'], $first->after['actor_online']]);
        });
    }
}
