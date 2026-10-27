<?php

namespace Modules\Pharmacy\Stock;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Pharmacy\Events\LotBlocked;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\LotBalance;
use Modules\Pharmacy\Models\LotMovement;
use Modules\Pharmacy\Models\Settings;
use Modules\Pharmacy\Support\PharmacyError;
use Modules\Pharmacy\Support\Qty;

/**
 * PHA-04, PHA-05, PHA-07 to PHA-09, PHA-11: lots, their stock per location
 * and the rules for selling them.
 *
 * - move(): the one way lot stock changes. The balance row is locked, the
 *   movement appended (once per source line and lot: a second delivery of
 *   the same source changes nothing) and the balance incremented in the same
 *   transaction. A movement that takes a lot below zero is flagged
 *   `negative_stock`; it is never refused here (a till's sale is kept).
 * - check(): whether a lot may be sold on a day under the company's
 *   settings: refused when expired, quarantined, recalled or closer to
 *   expiry than `min_sale_days`; warned (`near_expiry`) inside
 *   `near_expiry_days`.
 * - suggest(): first-expiry-first-out (PHA-07), over lots that may be sold.
 */
class Lots
{
    public function __construct(private readonly Auditor $auditor) {}

    /** The lot of $itemId with $batchNumber at $companyId (case-insensitive), or null. */
    public function find(string $companyId, string $itemId, string $batchNumber): ?Lot
    {
        return Lot::query()->where('company_id', $companyId)->where('item_id', $itemId)
            ->whereRaw('lower(batch_number) = lower(?)', [trim($batchNumber)])->first();
    }

    /**
     * The lot made on first use. Its expiry and manufacture date are those of
     * the first receipt; a later receipt naming other dates is refused
     * (`lot_dates_differ`), since one batch has one expiry.
     */
    public function ensure(string $companyId, string $itemId, string $batchNumber, string $expiresOn, ?string $manufacturedOn = null, ?string $supplierId = null): Lot
    {
        $batchNumber = trim($batchNumber);
        $lot = $this->find($companyId, $itemId, $batchNumber);

        if ($lot !== null) {
            if ($lot->expires_on->toDateString() !== CarbonImmutable::parse($expiresOn)->toDateString()) {
                throw PharmacyError::make(422, 'lot_dates_differ', ['batch' => $batchNumber, 'expires_on' => $lot->expires_on->toDateString()], 'expires_on');
            }

            return $lot;
        }

        try {
            return DB::connection(TenantContext::CONNECTION)->transaction(fn () => Lot::create([
                'company_id' => $companyId,
                'item_id' => $itemId,
                'batch_number' => $batchNumber,
                'manufactured_on' => $manufacturedOn,
                'expires_on' => $expiresOn,
                'supplier_id' => $supplierId,
                'status' => Lot::ACTIVE,
                // PHA-22: a batch recalled before this lot was received is blocked from the start.
                'recall_id' => $this->openRecallId($itemId, $batchNumber),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->find($companyId, $itemId, $batchNumber) ?? throw PharmacyError::make(409, 'lot_conflict');
        }
    }

    private function openRecallId(string $itemId, string $batchNumber): ?string
    {
        return DB::connection(TenantContext::CONNECTION)->table('pharmacy_recalls')
            ->where('item_id', $itemId)->where('status', 'open')->whereRaw('lower(batch_number) = lower(?)', [$batchNumber])->value('id');
    }

    /**
     * PHA-05: change a lot's stock at a location by the signed $qty (base unit).
     * Null when this source line already moved this lot (idempotent).
     *
     * @param  list<string>  $flags
     */
    public function move(
        Lot $lot,
        string $locationId,
        string $kind,
        BigDecimal $qty,
        string $sourceType,
        ?string $sourceId,
        ?string $sourceLineId = null,
        ?CarbonImmutable $at = null,
        array $flags = [],
        ?string $saleId = null,
        ?string $customerId = null,
        ?string $prescriptionId = null,
        ?string $userId = null,
        ?string $note = null,
        ?string $reference = null,
    ): ?LotMovement {
        $qty = Qty::of($qty);

        if ($qty->isZero()) {
            return null;
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($lot, $locationId, $kind, $qty, $sourceType, $sourceId, $sourceLineId, $at, $flags, $saleId, $customerId, $prescriptionId, $userId, $note, $reference) {
            $balance = $this->lockedBalance($lot, $locationId);

            if ($sourceId !== null) {
                $done = LotMovement::query()->where('lot_id', $lot->id)->where('location_id', $locationId)->where('kind', $kind)
                    ->where('source_type', $sourceType)->where('source_id', $sourceId)
                    ->when($sourceLineId === null, fn ($q) => $q->whereNull('source_line_id'), fn ($q) => $q->where('source_line_id', $sourceLineId))
                    ->exists();

                if ($done) {
                    return null;
                }
            }

            $after = Qty::of((string) $balance->qty)->plus($qty);

            if ($after->isNegative() && $qty->isNegative()) {
                $flags[] = 'negative_stock';
            }

            $movement = LotMovement::create([
                'company_id' => $lot->company_id,
                'location_id' => $locationId,
                'item_id' => $lot->item_id,
                'lot_id' => $lot->id,
                'kind' => $kind,
                'qty' => (string) $qty,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_line_id' => $sourceLineId,
                'sale_id' => $saleId,
                'reference' => $reference,
                'customer_id' => $customerId,
                'prescription_id' => $prescriptionId,
                'flags' => array_values(array_unique($flags)),
                'note' => $note,
                'created_by' => $userId,
                'occurred_at' => $at ?? CarbonImmutable::now(),
            ]);

            // The balance changes by an atomic increment under the row lock; sync re-stamps it (NFR-04).
            LotBalance::query()->whereKey($balance->id)->update(['qty' => DB::raw('qty + ('.$qty.')')]);

            return $movement;
        });
    }

    /** The balance row of $lot at $locationId, made if need be, locked for update. */
    private function lockedBalance(Lot $lot, string $locationId): LotBalance
    {
        $find = fn () => LotBalance::query()->where('lot_id', $lot->id)->where('location_id', $locationId)->lockForUpdate()->first();
        $existing = $find();

        if ($existing !== null) {
            return $existing;
        }

        try {
            DB::connection(TenantContext::CONNECTION)->transaction(fn () => LotBalance::create([
                'company_id' => $lot->company_id,
                'location_id' => $locationId,
                'item_id' => $lot->item_id,
                'lot_id' => $lot->id,
                'qty' => '0',
            ]));
        } catch (UniqueConstraintViolationException) {
            // Made meanwhile by a concurrent move.
        }

        return $find() ?? throw PharmacyError::make(409, 'lot_conflict');
    }

    /** The stock of $lot at $locationId (base unit), 0 when it was never there. */
    public function onHand(Lot $lot, string $locationId): BigDecimal
    {
        return Qty::of((string) (LotBalance::query()->where('lot_id', $lot->id)->where('location_id', $locationId)->value('qty') ?? '0'));
    }

    /**
     * PHA-08, PHA-09: whether $lot may be sold on $today (Y-m-d, the company's day).
     *
     * @return array{refuse: ?string, warn: ?string, days_left: int}
     */
    public function check(Lot $lot, Settings $settings, string $today): array
    {
        $days = (int) CarbonImmutable::parse($today)->diffInDays($lot->expires_on, false);
        $refuse = match (true) {
            $days < 0 => 'lot_expired',
            $lot->recall_id !== null => 'lot_recalled',
            $lot->status === Lot::QUARANTINED => 'lot_quarantined',
            $days < $settings->min_sale_days => 'lot_too_close',
            default => null,
        };

        return ['refuse' => $refuse, 'warn' => $refuse === null && $days <= $settings->near_expiry_days ? 'near_expiry' : null, 'days_left' => $days];
    }

    /**
     * PHA-07: the lots of $itemId at $locationId that may be sold, earliest
     * expiry first (then the oldest received, then batch number), each with
     * its stock and, for a wanted quantity, how much to take from it.
     *
     * @return array{lots: list<array{lot: Lot, on_hand: string, take: string, warn: ?string, days_left: int}>, blocked: list<array{lot: Lot, on_hand: string, reason: string}>, shortfall: string}
     */
    public function suggest(string $itemId, string $locationId, Settings $settings, string $today, ?BigDecimal $wanted = null): array
    {
        $balances = LotBalance::query()->with('lot')->where('item_id', $itemId)->where('location_id', $locationId)->where('qty', '>', 0)->get();
        $lots = [];
        $blocked = [];

        foreach ($balances->sortBy(fn (LotBalance $b) => [$b->lot->expires_on->toDateString(), $b->lot->created_at?->toIso8601String(), $b->lot->batch_number])->values() as $balance) {
            $verdict = $this->check($balance->lot, $settings, $today);

            if ($verdict['refuse'] !== null) {
                $blocked[] = ['lot' => $balance->lot, 'on_hand' => Qty::show($balance->qty), 'reason' => $verdict['refuse']];

                continue;
            }

            $lots[] = ['lot' => $balance->lot, 'on_hand' => Qty::show($balance->qty), 'take' => '0', 'warn' => $verdict['warn'], 'days_left' => $verdict['days_left']];
        }

        $left = $wanted === null ? Qty::zero() : Qty::of($wanted);

        foreach ($lots as $index => $row) {
            if (! $left->isPositive()) {
                break;
            }

            $take = Qty::min($left, Qty::of($row['on_hand']));
            $lots[$index]['take'] = Qty::show($take);
            $left = $left->minus($take);
        }

        return ['lots' => $lots, 'blocked' => $blocked, 'shortfall' => Qty::show($left->isPositive() ? $left : 0)];
    }

    /** PHA-11: quarantine a lot. Audited; the lot is no longer sellable anywhere. */
    public function quarantine(Lot $lot, string $reason, ?User $by, bool $automatic = false): Lot
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($lot, $reason, $by, $automatic) {
            $lot = Lot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();

            if ($lot->status === Lot::QUARANTINED) {
                return $lot;
            }

            $lot->fill(['status' => Lot::QUARANTINED, 'quarantine_reason' => $reason, 'quarantined_at' => CarbonImmutable::now(), 'quarantined_by' => $by?->id])->save();
            $this->touch($lot->id);
            $this->auditor->record('pharmacy.lot.quarantine', $lot, ['status' => Lot::ACTIVE], ['status' => Lot::QUARANTINED, 'reason' => $reason, 'automatic' => $automatic]);
            LotBlocked::dispatch($lot->tenant_id, $lot->id, $lot->company_id, $lot->item_id, 'quarantined');

            return $lot->refresh();
        });
    }

    /** PHA-11: release a quarantined lot (not while a recall is open on it). */
    public function release(Lot $lot, string $reason, User $by): Lot
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($lot, $reason) {
            $lot = Lot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();

            if ($lot->recall_id !== null) {
                throw PharmacyError::make(409, 'lot_recalled');
            }

            if ($lot->status !== Lot::QUARANTINED) {
                throw PharmacyError::make(409, 'lot_not_quarantined');
            }

            $lot->fill(['status' => Lot::ACTIVE, 'quarantine_reason' => null, 'quarantined_at' => null, 'quarantined_by' => null])->save();
            $this->touch($lot->id);
            $this->auditor->record('pharmacy.lot.release', $lot, ['status' => Lot::QUARANTINED], ['status' => Lot::ACTIVE, 'reason' => $reason]);

            return $lot->refresh();
        });
    }

    /** NFR-04: re-stamp a lot's balances, so tills pull its new state (blocked, released). */
    public function touch(string ...$lotIds): void
    {
        LotBalance::query()->whereIn('lot_id', $lotIds)->update(['updated_at' => CarbonImmutable::now()]);
    }
}
