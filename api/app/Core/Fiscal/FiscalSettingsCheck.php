<?php

namespace App\Core\Fiscal;

use App\Core\Fiscal\Models\FiscalSettings;

/** What a company's fiscal settings still lack for their driver (FiscalDriver::required). */
class FiscalSettingsCheck
{
    public function __construct(private readonly FiscalDrivers $drivers) {}

    /** @return list<string> */
    public function missing(FiscalSettings $settings): array
    {
        $missing = [];

        foreach ($this->drivers->get((string) $settings->driver)->required() as $field) {
            $value = str_starts_with($field, 'credentials.')
                ? $settings->credential(substr($field, strlen('credentials.')))
                : $settings->{$field};

            if (blank($value)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}
