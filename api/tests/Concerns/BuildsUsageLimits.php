<?php

namespace Tests\Concerns;

use App\Core\Limits\Allowances;
use App\Core\Limits\PlanAllowances;
use App\Core\Limits\PlanSelection;
use App\Core\Limits\Units;
use App\Core\Limits\UsageLimits;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Tests\Support\Limits\FixedAllowances;

/**
 * LIM-01..LIM-04 tests: allowances set per tenant (FixedAllowances in place
 * of billing's), devices in a given state, metering at a time, and a
 * tenant whose devices are over the plan past the 7-day window. Use with
 * BuildsOrganisation (the owner's tenant).
 */
trait BuildsUsageLimits
{
    protected FixedAllowances $allowances;

    protected function setUpLimits(): void
    {
        $this->allowances = new FixedAllowances;
        $this->app->instance(PlanAllowances::class, $this->allowances);
    }

    /** @param  array<string, ?int>  $limits */
    protected function allow(array $limits, ?string $tenantId = null, bool $priced = true): void
    {
        $plan = $priced ? new PlanSelection('KE', ['pos'], ['outlet' => $limits[Units::OUTLET] ?? 1, 'device' => $limits[Units::DEVICE] ?? 2]) : null;
        $this->allowances->set($tenantId ?? $this->owner->tenant_id, new Allowances($limits, $plan));
    }

    /** @return list<Device> $count devices at $location (location A by default) in $status */
    protected function devices(int $count, string $status = Device::STATUS_ACTIVE, ?Location $location = null): array
    {
        return $this->inTenant(function () use ($count, $status, $location) {
            $devices = [];

            for ($i = 1; $i <= $count; $i++) {
                $device = Device::create(['location_id' => ($location ?? $this->locationA)->id, 'name' => "Till {$status} {$i}"]);
                $device->forceFill(['status' => $status, 'paired_at' => $status === Device::STATUS_PENDING ? null : now()])->save();
                $devices[] = $device;
            }

            return $devices;
        });
    }

    /** Meter the owner's tenant at $at (now by default), as the daily job does. */
    protected function meter(?CarbonImmutable $at = null): array
    {
        return $this->inTenant(fn () => app(UsageLimits::class)->meter($at ?? CarbonImmutable::now()));
    }

    /**
     * Three active devices against an allowance of two, metered at 06:00 on
     * 2026-10-12, then 7 days later: adding devices is refused from now on.
     *
     * @return list<Device>
     */
    protected function blockDevices(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12T06:00:00Z'));
        $this->allow([Units::DEVICE => 2]);
        $devices = $this->devices(3);
        $this->meter();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T06:00:00Z'));
        $this->meter();

        return $devices;
    }
}
