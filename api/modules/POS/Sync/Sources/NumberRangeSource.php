<?php

namespace Modules\POS\Sync\Sources;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\POS\Models\NumberRange;
use Modules\POS\PosServiceProvider;

/**
 * NFR-04, NUM-02: the device's active receipt and refund ranges, so a till
 * restored or bootstrapped knows which numbers it may print. Ranges are
 * only given out by POST pos/number-ranges; this entity mirrors them.
 */
class NumberRangeSource implements SnapshotSource
{
    public function key(): string
    {
        return 'pos_number_ranges';
    }

    public function module(): string
    {
        return PosServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        return NumberRange::query()
            ->where('device_id', $scope->device->id)
            ->where('status', NumberRange::ACTIVE)
            ->orderBy('document_type')->orderBy('range_from')
            ->get()
            ->map(fn (NumberRange $range) => [
                'id' => $range->id,
                'document_type' => $range->document_type,
                'period' => $range->period,
                'pattern' => $range->pattern,
                'from' => $range->range_from,
                'to' => $range->range_to,
                'next' => $range->next_value,
            ])->all();
    }
}
