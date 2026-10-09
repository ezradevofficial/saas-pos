<?php

namespace App\Core\Fiscal\Http\Resources;

use App\Core\Fiscal\FiscalDrivers;
use App\Core\Fiscal\FiscalSettingsCheck;
use App\Core\Fiscal\Models\FiscalSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A company's fiscal settings. Credentials are never returned:
 * `credentials_set` names the keys that hold a value. `missing` lists what
 * the driver still needs before transmission can be switched on;
 * `drivers` the drivers the company may choose.
 *
 * @mixin FiscalSettings
 */
class FiscalSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'country' => $this->country,
            'driver' => $this->driver,
            'enabled' => $this->enabled,
            'tin' => $this->tin,
            'branch_code' => $this->branch_code,
            'device_serial' => $this->device_serial,
            'settings' => (object) ($this->settings ?? []),
            'credentials_set' => (object) $this->credentialsSet(),
            'initialized_at' => $this->initialized_at?->toIso8601String(),
            'missing' => app(FiscalSettingsCheck::class)->missing($this->resource),
            'drivers' => app(FiscalDrivers::class)->choices((string) $this->country),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
