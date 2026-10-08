<?php

namespace App\Core\Sync;

use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * NFR-04, TEN-05: when each device last pulled master data, pushed sales
 * (the POS module calls recordPush() after an upload) and bootstrapped,
 * shown in the devices API for the back office. Also moves `last_seen_at`.
 *
 * NFR-05: with 50,000 devices pulling every minute, a write per pull would
 * be a steady load for nothing: a time is only written when the stored one
 * is a minute old. Not audited (it is telemetry, not a change).
 */
class DeviceSyncStatus
{
    public const RESOLUTION_SECONDS = 60;

    public function recordPull(Device $device): void
    {
        $this->record($device, 'last_pull_at');
    }

    public function recordPush(Device $device): void
    {
        $this->record($device, 'last_push_at');
    }

    public function recordBootstrap(Device $device): void
    {
        $this->record($device, 'last_bootstrap_at', always: true);
    }

    private function record(Device $device, string $column, bool $always = false): void
    {
        $now = now();

        DB::connection(TenantContext::CONNECTION)->table('devices')
            ->where('id', $device->id)
            ->when(! $always, fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereNull($column)
                ->orWhere($column, '<', $now->copy()->subSeconds(self::RESOLUTION_SECONDS))))
            ->update([$column => $now, 'last_seen_at' => $now]);
    }
}
