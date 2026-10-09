<?php

namespace Modules\POS\Sync;

use App\Core\Audit\Auditor;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\CashMovement;
use Modules\POS\Models\Shift;

/**
 * POS-04: cash paid into or out of the drawer, uploaded by the till
 * (idempotent by id; other content under the same id is `payload_mismatch`).
 *
 * - Needs `pos.cash.move` for the person, or a manager's override (AUTH-08).
 * - A pay-out whose approval can't be proven (an unverified override, or
 *   a person without a sign-in attestation, AUTH-07) is **held**: it does
 *   not count in the drawer until approved in the back office (H2, H3).
 *   A pay-in (money in) is applied and flagged instead.
 * - The shift is this device's. On a closed shift, a movement made before
 *   the close is accepted, flagged `received_after_close`, and the shift's
 *   expected cash recounted (H4); one made after the close is refused.
 */
class CashMovementUploads
{
    public function __construct(
        private readonly Authority $authority,
        private readonly Auditor $auditor,
        private readonly ShiftCash $cash,
    ) {}

    /** @return list<array<string, mixed>> */
    public function upload(DevicePlace $place, array $movements): array
    {
        return array_map(function (array $data) use ($place) {
            $hash = Records::hash($data);
            $existing = $this->existing($place, $data['id'], $hash);

            if ($existing !== null) {
                return $existing;
            }

            try {
                return UploadResults::cashMovement(DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->store($place, $data, $hash)));
            } catch (Rejection $rejection) {
                return UploadResults::rejected($data['id'], $rejection);
            } catch (UniqueConstraintViolationException) {
                return $this->existing($place, $data['id'], $hash) ?? UploadResults::rejected($data['id'], new Rejection('id_conflict', 'id'));
            }
        }, $movements);
    }

    private function existing(DevicePlace $place, string $id, string $hash): ?array
    {
        $movement = CashMovement::query()->find($id);

        return match (true) {
            $movement === null => null,
            $movement->device_id !== $place->device->id => UploadResults::rejected($id, new Rejection('id_conflict', 'id')),
            $movement->payload_hash !== $hash => UploadResults::rejected($id, new Rejection('payload_mismatch', 'id')),
            default => UploadResults::cashMovement($movement),
        };
    }

    private function store(DevicePlace $place, array $data, string $hash): CashMovement
    {
        $at = CarbonImmutable::parse($data['occurred_at'])->utc();
        $flags = new Flags;
        $shift = Shift::query()->whereKey($data['shift_id'])->first() ?? throw new Rejection('shift_unknown', 'shift_id', retryable: true);

        if ($shift->device_id !== $place->device->id) {
            throw new Rejection('shift_other_device', 'shift_id');
        }

        if (! $shift->isOpen()) {
            if ($at->greaterThan($shift->closed_at)) {
                throw new Rejection('shift_closed', 'shift_id');
            }

            $flags->add('received_after_close');
        }

        if (! TenantCurrency::query()->where('code', $data['currency'])->exists()) {
            throw new Rejection('currency_unknown', 'currency');
        }

        $user = $this->authority->user($data['user_id'], 'user_id');
        $this->authority->checkNamed($data['override'] ?? null, 'override');
        $approval = $this->authority->approve($user, $data['override'] ?? null, $data['actor_proof'] ?? null, 'pos.cash.move', $place->scope(), null, $place->device, $data['id'], 'override', moneyOut: $data['kind'] === CashMovement::PAY_OUT);
        $held = $approval->held() && $data['kind'] === CashMovement::PAY_OUT;

        if ($approval->held()) {
            $flags->add($approval->flag());
        }

        foreach ($approval->reviewFlags() as $code) {
            $flags->add($code);
        }

        // AUTH-07: the person's own sign-in, reviewed when the record predates it or it is a day old.
        if (! $approval->byOverride()) {
            foreach ($this->authority->sessionFlags($place->device, $user, $data['actor_proof'] ?? null, $at) as $code) {
                $flags->add($code);
            }
        }

        $movement = CashMovement::create([
            'id' => $data['id'],
            'shift_id' => $shift->id,
            'device_id' => $place->device->id,
            'location_id' => $place->location->id,
            'kind' => $data['kind'],
            'currency' => $data['currency'],
            'amount_minor' => (string) $data['amount_minor'],
            'reason' => $data['reason'],
            'user_id' => $user->id,
            'approved_by' => $approval->approverId(),
            'override_verified' => $approval->verified,
            'status' => $held ? Records::HELD : Records::APPLIED,
            'flags' => $flags->all(),
            'payload_hash' => $hash,
            'occurred_at' => $at,
            'received_at' => now(),
        ]);

        $this->auditor->record($held ? 'pos.cash.hold' : "pos.cash.{$data['kind']}", $movement, null, [
            'shift_id' => $shift->id,
            'kind' => $movement->kind,
            'currency' => $movement->currency,
            'amount_minor' => (string) $movement->amount_minor,
            'reason' => $movement->reason,
            'approved_by' => $approval->approverId(),
            'override_verified' => $approval->verified,
            'flags' => $movement->flags,
        ], ['user_id' => $user->id, 'on_behalf_of_user_id' => $approval->approverId(), 'device_time' => $at]);

        if (! $held) {
            $this->cash->recountIfClosed($shift->id, 'cash_movement', $movement->id);
        }

        return $movement;
    }
}
