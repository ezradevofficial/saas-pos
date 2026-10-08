<?php

namespace Modules\POS\Sync;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\POS\Events\SaleVoided;
use Modules\POS\Models\Refund;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleVoid;

/**
 * POS-05: a whole sale voided at the till (idempotent by the void's id).
 * The void is a new record referencing the sale (ADR 004); the sale's
 * status becomes `voided`. The sale must be one of this location's,
 * completed and without refunds. Needs `pos.sale.void` for the person or a
 * manager's override (AUTH-08: both users recorded). Audited; raises
 * SaleVoided after commit.
 */
class VoidUploads
{
    public function __construct(
        private readonly Authority $authority,
        private readonly Auditor $auditor,
        private readonly TenantContext $tenants,
    ) {}

    /** @return list<array<string, mixed>> */
    public function upload(DevicePlace $place, array $voids): array
    {
        return array_map(function (array $data) use ($place) {
            $existing = SaleVoid::query()->find($data['id']);

            if ($existing !== null) {
                return $existing->device_id === $place->device->id
                    ? UploadResults::voided($existing)
                    : UploadResults::rejected($data['id'], new Rejection('id_conflict', 'id'));
            }

            try {
                return UploadResults::voided(DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->store($place, $data)));
            } catch (Rejection $rejection) {
                return UploadResults::rejected($data['id'], $rejection);
            } catch (UniqueConstraintViolationException) {
                $void = SaleVoid::query()->find($data['id']);

                return $void !== null && $void->device_id === $place->device->id
                    ? UploadResults::voided($void)
                    : UploadResults::rejected($data['id'], new Rejection('sale_already_voided', 'sale_id'));
            }
        }, $voids);
    }

    private function store(DevicePlace $place, array $data): SaleVoid
    {
        // Not uploaded yet (the device sends sales first) or another tenant's: retry later.
        $sale = Sale::query()->whereKey($data['sale_id'])->lockForUpdate()->first() ?? throw new Rejection('sale_unknown', 'sale_id', retryable: true);

        if ($sale->location_id !== $place->location->id) {
            throw new Rejection('sale_other_location', 'sale_id');
        }

        if ($sale->status === Sale::VOIDED) {
            throw new Rejection('sale_already_voided', 'sale_id');
        }

        if (Refund::query()->where('sale_id', $sale->id)->exists()) {
            throw new Rejection('sale_has_refunds', 'sale_id');
        }

        $voider = $this->authority->user($data['voided_by_id'], 'voided_by_id');
        $approval = $this->authority->approve($voider, $data['override'] ?? null, 'pos.sale.void', $place->scope(), null, $place->device, $sale->id, 'override');
        $at = CarbonImmutable::parse($data['voided_at'])->utc();

        $void = SaleVoid::create([
            'id' => $data['id'],
            'sale_id' => $sale->id,
            'device_id' => $place->device->id,
            'voided_by' => $voider->id,
            'approved_by' => $approval->approverId(),
            'override_verified' => $approval->verified,
            'reason' => $data['reason'],
            'voided_at' => $at,
            'received_at' => now(),
        ]);

        $sale->forceFill(['status' => Sale::VOIDED, 'voided_at' => $at])->save();

        $this->auditor->record('pos.sale.void', $sale, ['status' => Sale::COMPLETED], [
            'status' => Sale::VOIDED,
            'void_id' => $void->id,
            'reason' => $void->reason,
            'approved_by' => $approval->approverId(),
            'override_verified' => $approval->verified,
        ], ['user_id' => $voider->id, 'on_behalf_of_user_id' => $approval->approverId(), 'device_time' => $at]);
        SaleVoided::dispatch($this->tenants->require(), $sale->id, $void->id);

        return $void;
    }
}
