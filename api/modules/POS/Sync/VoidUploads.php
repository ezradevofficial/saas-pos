<?php

namespace Modules\POS\Sync;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\Refund;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleVoid;
use Modules\POS\Models\Shift;

/**
 * POS-05: a whole sale voided at the till (idempotent by the void's id;
 * other content under the same id is `payload_mismatch`). The void is a
 * new record referencing the sale (ADR 004). The sale must be one of this
 * location's, completed, without refunds, and without another void
 * applied or waiting.
 *
 * Needs `pos.sale.void` for the person or a manager's override (AUTH-08).
 * Proven: applied at once (the sale becomes `voided`, SaleVoided raised).
 * Not provable (H2, H3): **held** for review in the back office; the sale
 * stays completed and nothing is raised until it is approved.
 */
class VoidUploads
{
    public function __construct(
        private readonly Authority $authority,
        private readonly HeldRecords $records,
        private readonly Auditor $auditor,
    ) {}

    /** @return list<array<string, mixed>> */
    public function upload(DevicePlace $place, array $voids): array
    {
        return array_map(function (array $data) use ($place) {
            $hash = Records::hash($data);
            $existing = $this->existing($place, $data['id'], $hash);

            if ($existing !== null) {
                return $existing;
            }

            try {
                return UploadResults::voided(DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->store($place, $data, $hash)));
            } catch (Rejection $rejection) {
                return UploadResults::rejected($data['id'], $rejection);
            } catch (UniqueConstraintViolationException) {
                return $this->existing($place, $data['id'], $hash) ?? UploadResults::rejected($data['id'], new Rejection('sale_already_voided', 'sale_id'));
            }
        }, $voids);
    }

    private function existing(DevicePlace $place, string $id, string $hash): ?array
    {
        $void = SaleVoid::query()->find($id);

        return match (true) {
            $void === null => null,
            $void->device_id !== $place->device->id => UploadResults::rejected($id, new Rejection('id_conflict', 'id')),
            $void->payload_hash !== $hash => UploadResults::rejected($id, new Rejection('payload_mismatch', 'id')),
            default => UploadResults::voided($void),
        };
    }

    private function store(DevicePlace $place, array $data, string $hash): SaleVoid
    {
        // Not uploaded yet (the device sends sales first) or another tenant's: retry later.
        $sale = Sale::query()->whereKey($data['sale_id'])->lockForUpdate()->first() ?? throw new Rejection('sale_unknown', 'sale_id', retryable: true);

        if ($sale->location_id !== $place->location->id) {
            throw new Rejection('sale_other_location', 'sale_id');
        }

        if ($sale->status === Sale::VOIDED || SaleVoid::query()->where('sale_id', $sale->id)->where('status', Records::HELD)->exists()) {
            throw new Rejection('sale_already_voided', 'sale_id');
        }

        if (Refund::query()->where('sale_id', $sale->id)->where('status', '<>', Records::REJECTED)->exists()) {
            throw new Rejection('sale_has_refunds', 'sale_id');
        }

        $voider = $this->authority->user($data['voided_by_id'], 'voided_by_id');
        $this->authority->checkNamed($data['override'] ?? null, 'override');
        $approval = $this->authority->approve($voider, $data['override'] ?? null, $data['actor_proof'] ?? null, 'pos.sale.void', $place->scope(), null, $place->device, $data['id'], 'override');
        $at = CarbonImmutable::parse($data['voided_at'])->utc();
        $flags = new Flags;

        if ($approval->held()) {
            $flags->add($approval->flag());
        }

        foreach ($approval->reviewFlags() as $code) {
            $flags->add($code);
        }

        if (Shift::query()->whereKey($sale->shift_id)->value('status') === Shift::CLOSED) {
            $flags->add('received_after_close');
        }

        $void = SaleVoid::create([
            'id' => $data['id'],
            'sale_id' => $sale->id,
            'device_id' => $place->device->id,
            'voided_by' => $voider->id,
            'approved_by' => $approval->approverId(),
            'override_verified' => $approval->verified,
            'status' => Records::HELD,
            'flags' => $flags->all(),
            'payload_hash' => $hash,
            'reason' => $data['reason'],
            'voided_at' => $at,
            'received_at' => now(),
        ]);

        if ($approval->held()) {
            $this->auditor->record('pos.sale.void_hold', $sale, null, [
                'void_id' => $void->id, 'reason' => $void->reason, 'approved_by' => $approval->approverId(), 'flags' => $void->flags,
            ], ['user_id' => $voider->id, 'on_behalf_of_user_id' => $approval->approverId(), 'device_time' => $at]);
        } else {
            $this->records->applyVoid($void);
        }

        return $void;
    }
}
