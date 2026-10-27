<?php

namespace Modules\Pharmacy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Models\Settings;

/**
 * PHA-02: a company's pharmacy settings (the defaults when it saved none).
 * No value here is a regulator's number: all are "to confirm" (docs/modules/pharmacy.md).
 *
 * @mixin Settings
 */
class SettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Settings $settings */
        $settings = $this->resource;

        return [
            'company_id' => $settings->company_id,
            'configured' => $settings->exists,
            'near_expiry_days' => (int) $settings->near_expiry_days,
            'min_sale_days' => (int) $settings->min_sale_days,
            'prescription_classes' => array_values($settings->prescription_classes),
            'pharmacist_classes' => array_values($settings->pharmacist_classes),
            'prescription_valid_days' => $settings->prescription_valid_days,
            'quarantine_expired' => (bool) $settings->quarantine_expired,
        ];
    }
}
