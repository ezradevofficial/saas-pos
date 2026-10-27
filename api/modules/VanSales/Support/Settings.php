<?php

namespace Modules\VanSales\Support;

use Modules\VanSales\Models\Setting;

/** VAN-24, VAN-11: a company's settings with their defaults (no row is the defaults). */
final class Settings
{
    /** @return array{company_id: string, gps_checkin: string, gps_retention_days: int, flag_offline_credit: bool} */
    public static function for(string $companyId): array
    {
        $row = Setting::query()->where('company_id', $companyId)->first();

        return [
            'company_id' => $companyId,
            'gps_checkin' => $row?->gps_checkin ?? 'off',
            'gps_retention_days' => (int) ($row?->gps_retention_days ?? 30),
            'flag_offline_credit' => (bool) ($row?->flag_offline_credit ?? true),
        ];
    }
}
