<?php

namespace Modules\POS\Sync;

use App\Core\Numbering\NumberContext;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use DateTimeInterface;

/**
 * The signed-in device and where it sits (TEN-05): its location, branch
 * and company, read once per request under the device's tenant (RLS).
 * Every upload is checked against this place, never against ids the
 * device sends for it.
 */
final class DevicePlace
{
    private function __construct(
        public readonly Device $device,
        public readonly Location $location,
        public readonly Branch $branch,
        public readonly Company $company,
    ) {}

    public static function of(Device $device): self
    {
        $location = $device->location()->with('branch.company')->firstOrFail();

        return new self($device, $location, $location->branch, $location->branch->company);
    }

    public function scope(): Scope
    {
        return Scope::location($this->location->id);
    }

    public function numberContext(?DateTimeInterface $at = null): NumberContext
    {
        return new NumberContext($this->company, $this->branch, $this->location, $this->device, $at);
    }

    /** @return array{company_id: string, branch_id: string, location_id: string, device_id: string} */
    public function columns(): array
    {
        return [
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'location_id' => $this->location->id,
            'device_id' => $this->device->id,
        ];
    }
}
