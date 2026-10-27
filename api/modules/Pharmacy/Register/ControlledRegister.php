<?php

namespace Modules\Pharmacy\Register;

use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\Prescription;
use Modules\Pharmacy\Models\RegisterEntry;
use Modules\Pharmacy\Models\RegisterHead;
use Modules\Pharmacy\Support\PharmacyError;
use Modules\Pharmacy\Support\Qty;

/**
 * PHA-18: the controlled-drug register. One append-only ledger per
 * controlled item and location; entry numbers run 1, 2, 3 and each entry
 * keeps the balance after it. The head row of the item and place is locked
 * while an entry is appended, so two entries never share a number and the
 * balance never loses an update. The database refuses updates and deletes
 * of entries (trigger, also for the owner role); a correction is a new
 * `adjustment` entry.
 */
class ControlledRegister
{
    /**
     * Append an entry; null when the same source line already wrote this
     * kind for this lot (idempotent). $qty is signed: receipts, returns and
     * transfers in are positive; dispenses, write-offs and transfers out
     * negative.
     */
    public function append(
        string $companyId,
        string $locationId,
        string $itemId,
        string $kind,
        BigDecimal $qty,
        string $sourceType,
        ?string $sourceId,
        ?string $sourceLineId = null,
        ?Lot $lot = null,
        ?Prescription $prescription = null,
        ?string $pharmacistId = null,
        ?string $recordedBy = null,
        ?string $reason = null,
        ?CarbonImmutable $at = null,
    ): ?RegisterEntry {
        $qty = Qty::of($qty);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($companyId, $locationId, $itemId, $kind, $qty, $sourceType, $sourceId, $sourceLineId, $lot, $prescription, $pharmacistId, $recordedBy, $reason, $at) {
            $head = $this->lockedHead($companyId, $locationId, $itemId);

            if ($sourceId !== null) {
                $done = RegisterEntry::query()->where('location_id', $locationId)->where('item_id', $itemId)->where('kind', $kind)
                    ->where('source_type', $sourceType)->where('source_id', $sourceId)
                    ->when($sourceLineId === null, fn ($q) => $q->whereNull('source_line_id'), fn ($q) => $q->where('source_line_id', $sourceLineId))
                    ->when($lot === null, fn ($q) => $q->whereNull('lot_id'), fn ($q) => $q->where('lot_id', $lot->id))
                    ->exists();

                if ($done) {
                    return null;
                }
            }

            $number = $head->last_no + 1;
            $balance = Qty::of((string) $head->balance)->plus($qty);

            $entry = RegisterEntry::create([
                'company_id' => $companyId,
                'location_id' => $locationId,
                'item_id' => $itemId,
                'entry_no' => $number,
                'kind' => $kind,
                'qty' => (string) $qty,
                'balance' => (string) $balance,
                'lot_id' => $lot?->id,
                'batch_number' => $lot?->batch_number,
                'expires_on' => $lot?->expires_on?->toDateString(),
                'prescription_id' => $prescription?->id,
                'prescriber_name' => $prescription?->prescriber_name,
                'prescriber_licence' => $prescription?->prescriber_licence,
                'patient_name' => $prescription?->patient_name,
                'pharmacist_id' => $pharmacistId,
                'recorded_by' => $recordedBy,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_line_id' => $sourceLineId,
                'reason' => $reason,
                'occurred_at' => $at ?? CarbonImmutable::now(),
            ]);

            RegisterHead::query()->whereKey($head->id)->update(['last_no' => $number, 'balance' => (string) $balance]);

            return $entry;
        });
    }

    /** The head of the register of $itemId at $locationId, made if need be, locked for update. */
    private function lockedHead(string $companyId, string $locationId, string $itemId): RegisterHead
    {
        $find = fn () => RegisterHead::query()->where('location_id', $locationId)->where('item_id', $itemId)->lockForUpdate()->first();
        $existing = $find();

        if ($existing !== null) {
            return $existing;
        }

        try {
            DB::connection(TenantContext::CONNECTION)->transaction(fn () => RegisterHead::create([
                'company_id' => $companyId, 'location_id' => $locationId, 'item_id' => $itemId, 'last_no' => 0, 'balance' => '0',
            ]));
        } catch (UniqueConstraintViolationException) {
            // Made meanwhile by a concurrent entry.
        }

        return $find() ?? throw PharmacyError::make(409, 'register_conflict');
    }

    /** The balance of the register of $itemId at $locationId (0 when it has no entries). */
    public function balance(string $locationId, string $itemId): string
    {
        return Qty::show(RegisterHead::query()->where('location_id', $locationId)->where('item_id', $itemId)->value('balance') ?? '0');
    }
}
