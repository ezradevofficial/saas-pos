<?php

namespace Modules\POS\Sync;

use App\Core\Audit\Auditor;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\NumberRange;

/**
 * NUM-02: number ranges of a device.
 *
 * - topUp(): the device reports the next number it will use; when fewer
 *   than `pos.ranges.threshold` numbers remain across its active ranges, a
 *   new block of `pos.ranges.size` is reserved from the type's NUM-01
 *   counter (Numbering::reserve, under the counter's row lock: blocks never
 *   overlap, and an exclusion constraint backs it). A range of a past
 *   period (yearly formats) is retired.
 * - claim(): an uploaded receipt number must come from one of the
 *   device's ranges (any status: it may have been printed before the range
 *   ran out or was retired) and match the range's pattern for that number
 *   and the document's date; the database refuses a number used twice.
 * - retire(): a lost device's ranges stop (on unpair).
 */
class NumberRanges
{
    public const TYPES = ['pos.receipt', 'pos.refund'];

    public function __construct(
        private readonly Numbering $numbering,
        private readonly Auditor $auditor,
    ) {}

    /** @return Collection<int, NumberRange> the device's active ranges of $type, oldest first */
    public function topUp(DevicePlace $place, string $type, ?int $deviceNext = null): Collection
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($place, $type, $deviceNext) {
            DB::connection(TenantContext::CONNECTION)->select(
                'select pg_advisory_xact_lock(hashtextextended(?, 0))',
                ["pos_number_ranges:{$place->device->id}:{$type}"],
            );

            $context = $place->numberContext();
            $format = $this->numbering->formatFor($type, $place->company->id, $place->branch->id);
            $period = $this->numbering->period($format, $context);

            $ranges = $this->active($place, $type);

            // Only a number inside (or just past) one of the device's own ranges says anything.
            $own = $deviceNext !== null && $ranges->contains(fn (NumberRange $r) => $deviceNext >= $r->range_from && $deviceNext <= $r->range_to + 1);

            foreach ($ranges as $range) {
                if ($range->period !== $period) {
                    $this->retireRange($range, 'period_ended');

                    continue;
                }

                if ($own) {
                    $this->markUsedBelow($range, $deviceNext);
                }
            }

            $ranges = $this->active($place, $type);
            $remaining = $ranges->sum(fn (NumberRange $range) => $range->remaining());

            if ($remaining < (int) config('pos.ranges.threshold')) {
                $block = $this->numbering->reserve($type, $context, (int) config('pos.ranges.size'));

                $range = NumberRange::create([
                    'device_id' => $place->device->id,
                    'document_type' => $type,
                    'number_sequence_id' => $block->sequenceId,
                    'period' => $block->period,
                    'pattern' => $block->pattern,
                    'range_from' => $block->from,
                    'range_to' => $block->to,
                    'next_value' => $block->from,
                    'status' => NumberRange::ACTIVE,
                    'allocated_at' => now(),
                ]);
                $this->auditor->record('pos.number_range.allocate', $range, null, $range->only(['device_id', 'document_type', 'period', 'pattern', 'range_from', 'range_to']));

                $ranges = $this->active($place, $type);
            }

            return $ranges;
        });
    }

    /**
     * The device's range that gave $number for $type (M1): among its ranges
     * holding $value (several periods of a yearly format can), the one
     * whose pattern renders exactly $number for $at; the till may name it
     * (`number_range_id`). Locked; marks the value used. Call inside the
     * document's transaction.
     */
    public function claim(DevicePlace $place, string $type, int $value, string $number, DateTimeInterface $at, string $field, ?string $rangeId = null): NumberRange
    {
        $candidates = NumberRange::query()
            ->where('device_id', $place->device->id)
            ->where('document_type', $type)
            ->where('range_from', '<=', $value)
            ->where('range_to', '>=', $value)
            ->when($rangeId !== null, fn ($q) => $q->whereKey($rangeId))
            ->orderBy('allocated_at')
            ->lockForUpdate()
            ->get();

        if ($candidates->isEmpty()) {
            throw new Rejection('receipt_range_unknown', "{$field}_seq");
        }

        $context = $place->numberContext(CarbonImmutable::instance($at));
        $range = $candidates->first(fn (NumberRange $r) => Numbering::renderFrozen($r->pattern, $context, $value) === $number)
            ?? throw new Rejection('receipt_number_mismatch', "{$field}_number");

        $this->markUsedBelow($range, $value + 1);

        return $range;
    }

    /** NUM-02: a lost device's active ranges stop; their unused numbers are never given out again. Audited. */
    public function retire(string $deviceId): int
    {
        $ranges = NumberRange::query()->where('device_id', $deviceId)->where('status', NumberRange::ACTIVE)->lockForUpdate()->get();
        $ranges->each(fn (NumberRange $range) => $this->retireRange($range, 'device_unpaired'));

        return $ranges->count();
    }

    private function retireRange(NumberRange $range, string $reason): void
    {
        $range->forceFill(['status' => NumberRange::RETIRED, 'retired_at' => now()])->save();
        $this->auditor->record('pos.number_range.retire', $range, ['status' => NumberRange::ACTIVE], ['status' => NumberRange::RETIRED, 'reason' => $reason, 'next_value' => $range->next_value]);
    }

    /** True when the exception is a second use of one range number (unique range and number). */
    public static function isReuse(UniqueConstraintViolationException $e): bool
    {
        return str_contains($e->getMessage(), 'receipt_seq_unique') || str_contains($e->getMessage(), 'receipt_number_unique');
    }

    /** @return Collection<int, NumberRange> */
    private function active(DevicePlace $place, string $type): Collection
    {
        return NumberRange::query()
            ->where('device_id', $place->device->id)
            ->where('document_type', $type)
            ->where('status', NumberRange::ACTIVE)
            ->orderBy('range_from')
            ->lockForUpdate()
            ->get();
    }

    /** Numbers below $next in $range are used: move its next value up, exhausted past its end. */
    private function markUsedBelow(NumberRange $range, int $next): void
    {
        if ($next <= $range->next_value || $next < $range->range_from) {
            return;
        }

        $range->next_value = min($next, $range->range_to + 1);

        if ($range->next_value > $range->range_to && $range->status === NumberRange::ACTIVE) {
            $range->status = NumberRange::EXHAUSTED;
        }

        $range->save();
    }
}
