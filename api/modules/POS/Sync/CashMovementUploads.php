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
 * (idempotent by id). Needs `pos.cash.move` for the person, or a manager's
 * override (AUTH-08), and an open shift of this device. Audited.
 */
class CashMovementUploads
{
    public function __construct(
        private readonly Authority $authority,
        private readonly Auditor $auditor,
    ) {}

    /** @return list<array<string, mixed>> */
    public function upload(DevicePlace $place, array $movements): array
    {
        return array_map(function (array $data) use ($place) {
            $existing = CashMovement::query()->find($data['id']);

            if ($existing !== null) {
                return $existing->device_id === $place->device->id
                    ? UploadResults::cashMovement($existing)
                    : UploadResults::rejected($data['id'], new Rejection('id_conflict', 'id'));
            }

            try {
                return UploadResults::cashMovement(DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->store($place, $data)));
            } catch (Rejection $rejection) {
                return UploadResults::rejected($data['id'], $rejection);
            } catch (UniqueConstraintViolationException) {
                $movement = CashMovement::query()->find($data['id']);

                return $movement !== null && $movement->device_id === $place->device->id
                    ? UploadResults::cashMovement($movement)
                    : UploadResults::rejected($data['id'], new Rejection('id_conflict', 'id'));
            }
        }, $movements);
    }

    private function store(DevicePlace $place, array $data): CashMovement
    {
        $shift = Shift::query()->whereKey($data['shift_id'])->lockForUpdate()->first() ?? throw new Rejection('shift_unknown', 'shift_id', retryable: true);

        if ($shift->device_id !== $place->device->id) {
            throw new Rejection('shift_other_device', 'shift_id');
        }

        if (! $shift->isOpen()) {
            throw new Rejection('shift_closed', 'shift_id');
        }

        if (! TenantCurrency::query()->where('code', $data['currency'])->exists()) {
            throw new Rejection('currency_unknown', 'currency');
        }

        $user = $this->authority->user($data['user_id'], 'user_id');
        $approval = $this->authority->approve($user, $data['override'] ?? null, 'pos.cash.move', $place->scope(), null, $place->device, $data['id'], 'override');
        $at = CarbonImmutable::parse($data['occurred_at'])->utc();

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
            'occurred_at' => $at,
            'received_at' => now(),
        ]);

        $this->auditor->record("pos.cash.{$data['kind']}", $movement, null, [
            'shift_id' => $shift->id,
            'currency' => $movement->currency,
            'amount_minor' => (string) $movement->amount_minor,
            'reason' => $movement->reason,
            'approved_by' => $approval->approverId(),
            'override_verified' => $approval->verified,
        ], ['user_id' => $user->id, 'on_behalf_of_user_id' => $approval->approverId(), 'device_time' => $at]);

        return $movement;
    }
}
