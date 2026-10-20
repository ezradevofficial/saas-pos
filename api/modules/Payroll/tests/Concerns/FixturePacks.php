<?php

namespace Modules\Payroll\Tests\Concerns;

/**
 * TEST FIXTURES ONLY. Fictional statutory figures, made up for tests and
 * deliberately round so results can be checked by hand: they are NOT the
 * Kenyan or Congolese rates and must never be copied into the country
 * packs (CLAUDE.md: rates are never invented). Each fixture is the real
 * pack's structure (read from api/country-packs) with these values filled
 * in and confirmed (research status removed), so the tests also prove the
 * shipped structure works. Values a fixture does not set keep the shipped
 * (researched) figure, e.g. the KE pre-tax deduction limits.
 */
final class FixturePacks
{
    /** Kenya-shaped, fictional: KES cents. */
    public static function ke(array $overrides = []): array
    {
        return self::fill('KE', [
            'paye' => ['bands' => [['up_to_minor' => '1000000', 'rate' => '10'], ['up_to_minor' => '3000000', 'rate' => '20'], ['up_to_minor' => null, 'rate' => '30']], 'rounding' => ['mode' => 'half_up', 'unit_minor' => '1']],
            'personal_relief' => ['amount_minor' => '100000'],
            'insurance_relief' => ['rate' => '10', 'cap_minor' => '50000'],
            'nssf' => ['tiers' => [['up_to_minor' => '500000', 'employee_rate' => '5', 'employer_rate' => '5'], ['up_to_minor' => '2000000', 'employee_rate' => '5', 'employer_rate' => '5']], 'reduces_taxable' => true, 'rounding' => ['mode' => 'half_up', 'unit_minor' => '100']],
            'shif' => ['employee_rate' => '2', 'employee_min_minor' => '30000', 'reduces_taxable' => true, 'rounding' => ['mode' => 'half_up', 'unit_minor' => '1']],
            'ahl' => ['employee_rate' => '1', 'employer_rate' => '1', 'reduces_taxable' => false, 'rounding' => ['mode' => 'half_up', 'unit_minor' => '1']],
            'nita' => ['employer_minor' => '5000', 'rounding' => ['mode' => 'half_up', 'unit_minor' => '1']],
            ...$overrides,
        ], ['mpesa_b2c_bulk' => ['columns' => ['phone', 'amount', 'name', 'reference'], 'needs_confirmation' => true]]);
    }

    /** DR Congo-shaped, fictional: CDF (no decimals). */
    public static function cd(array $overrides = []): array
    {
        return self::fill('CD', [
            'ipr' => ['bands' => [['up_to_minor' => '100000', 'rate' => '0'], ['up_to_minor' => '1000000', 'rate' => '15'], ['up_to_minor' => null, 'rate' => '30']], 'max_rate' => '25', 'min_minor' => '__absent__', 'rounding' => ['mode' => 'half_up', 'unit_minor' => '1']],
            'ipr_dependants' => ['rate' => '2', 'max_dependants' => 9],
            // No ceiling in the fixture: the value is removed (absent = does not apply).
            'cnss' => ['employee_rate' => '4', 'employer_rate' => '4', 'base_cap_minor' => '__absent__', 'reduces_taxable' => true, 'rounding' => ['mode' => 'half_up', 'unit_minor' => '1']],
            'cnss_risks' => ['employer_rate' => '1', 'rounding' => ['mode' => 'half_up', 'unit_minor' => '1']],
            'cnss_family' => ['employer_rate' => '5', 'rounding' => ['mode' => 'half_up', 'unit_minor' => '1']],
            'inpp' => ['brackets' => [['up_to_employees' => 50, 'rate' => '2'], ['up_to_employees' => null, 'rate' => '1']], 'rounding' => ['mode' => 'half_up', 'unit_minor' => '1']],
            'onem' => ['employer_rate' => '0.5', 'rounding' => ['mode' => 'half_up', 'unit_minor' => '1']],
            'family_allowance' => ['amount_minor' => '10000', 'taxable' => false, 'contributory' => false, 'rounding' => ['mode' => 'half_up', 'unit_minor' => '1']],
            ...$overrides,
        ], []);
    }

    /** The real pack of $country with $values filled into each component's period, confirmed. */
    private static function fill(string $country, array $values, array $formats): array
    {
        $pack = json_decode((string) file_get_contents(base_path("country-packs/{$country}/payroll.json")), true, 32, JSON_THROW_ON_ERROR);

        foreach ($pack['components'] as &$component) {
            foreach ($component['periods'] as &$period) {
                $period['needs_confirmation'] = false;
                // Fixture values are fictional and confirmed: no research status (CP-01).
                unset($period['status'], $period['confidence'], $period['researched_on'], $period['source'], $period['assumed']);

                foreach ($values[$component['code']] ?? [] as $key => $value) {
                    if ($value === '__absent__') {
                        unset($period[$key]);
                    } else {
                        $period[$key] = $value;
                    }
                }
            }
        }

        unset($component, $period);
        $pack['payment_formats'] = $formats;

        return $pack;
    }
}
