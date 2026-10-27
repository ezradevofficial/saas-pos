<?php

namespace Modules\Pharmacy\Sync;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\Pharmacy\Models\Settings;
use Modules\Pharmacy\PharmacyServiceProvider;

/**
 * PHA-25, NFR-04: the pharmacy rules the till applies offline
 * (`pharmacy_settings`, a snapshot): a `module` row (always, while the
 * module is on) and the till's company settings (`settings`): the
 * near-expiry window, the minimum days to expiry for a sale, which item
 * classes need a prescription or a pharmacist, and how long a prescription
 * is valid. Personal data never travels: the till holds rules, not patients.
 */
class SettingsSource implements SnapshotSource
{
    public function key(): string
    {
        return 'pharmacy_settings';
    }

    public function module(): string
    {
        return PharmacyServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $settings = Settings::for($scope->companyId());

        return [
            ['id' => 'module', 'active' => true],
            [
                'id' => 'settings',
                'near_expiry_days' => (int) $settings->near_expiry_days,
                'min_sale_days' => (int) $settings->min_sale_days,
                'prescription_classes' => array_values($settings->prescription_classes),
                'pharmacist_classes' => array_values($settings->pharmacist_classes),
                'prescription_valid_days' => $settings->prescription_valid_days,
            ],
        ];
    }
}
