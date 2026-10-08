<?php

namespace Modules\POS\Sync\Sources;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\POS\Models\Shift;
use Modules\POS\PosServiceProvider;

/**
 * NFR-04, POS-04: the device's open shift as the server knows it (none,
 * or one row with its opener and float), so a reinstalled till resumes it
 * instead of opening a second one.
 */
class OpenShiftSource implements SnapshotSource
{
    public function key(): string
    {
        return 'pos_open_shift';
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
        return Shift::query()
            ->where('device_id', $scope->device->id)
            ->where('status', Shift::OPEN)
            ->with('balances')
            ->get()
            ->map(fn (Shift $shift) => [
                'id' => $shift->id,
                'opened_by' => $shift->opened_by,
                'opened_at' => $shift->opened_at->toIso8601String(),
                'opening_float' => $shift->balances->map(fn ($b) => ['currency' => $b->currency, 'amount_minor' => (string) $b->opening_minor])->all(),
            ])->all();
    }
}
