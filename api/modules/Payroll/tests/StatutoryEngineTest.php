<?php

namespace Modules\Payroll\Tests;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Payroll\Statutory\MissingValues;
use Modules\Payroll\Statutory\PayrollPack;
use Modules\Payroll\Statutory\StatutoryEngine;
use Modules\Payroll\Statutory\StatutoryInput;
use Modules\Payroll\Tests\Concerns\FixturePacks;
use Tests\TestCase;

// PAY-07, PAY-08: the statutory engine on injected fixture rates
// (fictional, FixturePacks), both countries: bands, tiers, caps, floors,
// reliefs, reductions, allowances, headcount brackets and each rounding
// rule; and the missing-values check on the shipped packs.
class StatutoryEngineTest extends TestCase
{
    private function compute(array $pack, StatutoryInput $input, string $on = '2026-10-31'): array
    {
        $result = (new StatutoryEngine)->compute(PayrollPack::inForce($pack, CarbonImmutable::parse($on)), $input);
        $by = [];

        foreach ($result->components as $c) {
            $by[$c['code']] = $c;
        }

        return ['result' => $result, 'by' => $by];
    }

    public function test_kenya_shaped_pack_bands_tiers_floor_reliefs(): void
    {
        $r = $this->compute(FixturePacks::ke(), new StatutoryInput(gross: '5000000', taxableGross: '5000000', contributoryGross: '5000000', basic: '5000000', insurancePremiums: '200000'));
        $by = $r['by'];

        // Tiers: 500 000 × 5 % + 1 500 000 × 5 % (the second tier caps the base), each side.
        $this->assertSame('100000', $by['nssf']['employee_minor']);
        $this->assertSame('100000', $by['nssf']['employer_minor']);
        $this->assertSame('100000', $by['shif']['employee_minor']);
        $this->assertSame('50000', $by['ahl']['employee_minor']);
        $this->assertSame('50000', $by['ahl']['employer_minor']);
        $this->assertSame('5000', $by['nita']['employer_minor']);
        $this->assertSame('0', $by['nita']['employee_minor']);
        // Taxable: 5 000 000 − NSSF − SHIF (both reduce taxable pay in the fixture; the levy does not).
        $this->assertSame('4800000', $r['result']->taxablePay);
        // Bands 100 000 + 400 000 + 540 000 = 1 040 000, less 100 000 personal relief and 20 000 insurance relief.
        $this->assertSame('920000', $by['paye']['employee_minor']);
        $this->assertSame([['code' => 'personal_relief', 'amount_minor' => '100000'], ['code' => 'insurance_relief', 'amount_minor' => '20000']], $by['paye']['trace']['reliefs']);
        $this->assertSame('1170000', $r['result']->employeeDeductions());
        $this->assertSame('155000', $r['result']->employer());
    }

    public function test_floor_and_zero_tax_after_relief_and_insurance_relief_only_when_premiums(): void
    {
        $r = $this->compute(FixturePacks::ke(), new StatutoryInput(gross: '1000000', taxableGross: '1000000', contributoryGross: '1000000', basic: '1000000'));

        // 2 % of 1 000 000 is 20 000, below the 30 000 floor.
        $this->assertSame('30000', $r['by']['shif']['employee_minor']);
        // 92 000 of tax, less 100 000 of relief, floored at zero.
        $this->assertSame('0', $r['by']['paye']['employee_minor']);
        $this->assertSame([['code' => 'personal_relief', 'amount_minor' => '92000']], $r['by']['paye']['trace']['reliefs']);

        // No base: no floor (never a deduction from nothing).
        $none = $this->compute(FixturePacks::ke(), new StatutoryInput(gross: '0', taxableGross: '0', contributoryGross: '0', basic: '0'));
        $this->assertSame('0', $none['by']['shif']['employee_minor']);
    }

    public function test_each_rounding_rule_is_applied_once(): void
    {
        $input = new StatutoryInput(gross: '1234567', taxableGross: '1234567', contributoryGross: '1234567', basic: '1234567');
        // Tiers: 25 000 + 36 728.35 = 61 728.35, to whole hundreds.
        $this->assertSame('61700', $this->compute(FixturePacks::ke(), $input)['by']['nssf']['employee_minor']);

        $pack = FixturePacks::ke(['nssf' => ['tiers' => collect(FixturePacks::ke()['components'])->firstWhere('code', 'nssf')['periods'][0]['tiers'], 'reduces_taxable' => true, 'rounding' => ['mode' => 'up', 'unit_minor' => '100']]]);
        $this->assertSame('61800', $this->compute($pack, $input)['by']['nssf']['employee_minor']);

        $pack = FixturePacks::ke(['nssf' => ['tiers' => collect(FixturePacks::ke()['components'])->firstWhere('code', 'nssf')['periods'][0]['tiers'], 'reduces_taxable' => true, 'rounding' => ['mode' => 'down', 'unit_minor' => '1']]]);
        $this->assertSame('61728', $this->compute($pack, $input)['by']['nssf']['employee_minor']);
    }

    public function test_congo_shaped_pack_allowance_headcount_dependants_and_cap(): void
    {
        $r = $this->compute(FixturePacks::cd(), new StatutoryInput(gross: '2000000', taxableGross: '2000000', contributoryGross: '2000000', basic: '2000000', dependants: 2, children: 3, headcount: 10));
        $by = $r['by'];

        $this->assertSame('30000', $by['family_allowance']['employee_minor']);
        $this->assertSame('2030000', $r['result']->gross);
        $this->assertSame('30000', $r['result']->allowances());
        // CNSS on contributory gross (the allowance is not contributory in the fixture).
        $this->assertSame('80000', $by['cnss']['employee_minor']);
        $this->assertSame('80000', $by['cnss']['employer_minor']);
        $this->assertSame('20000', $by['cnss_risks']['employer_minor']);
        $this->assertSame('100000', $by['cnss_family']['employer_minor']);
        // INPP: headcount 10 is in the first bracket (2 %), on gross with the allowance.
        $this->assertSame('40600', $by['inpp']['employer_minor']);
        // ONEM on the taxable base (the allowance is not taxable in the fixture): 2 000 000 × 0.5 %.
        $this->assertSame('10000', $by['onem']['employer_minor']);
        // IPR: 0 + 135 000 + 276 000 = 411 000, less 2 × 2 % = 394 560 (below the 25 % cap).
        $this->assertSame('1920000', $r['result']->taxablePay);
        $this->assertSame('394560', $by['ipr']['employee_minor']);
        $this->assertSame('ipr_dependants', $by['ipr']['trace']['reliefs'][0]['code']);

        // A large salary is capped at 25 % of taxable pay; a large company takes the second bracket.
        $big = $this->compute(FixturePacks::cd(), new StatutoryInput(gross: '100000000', taxableGross: '100000000', contributoryGross: '0', basic: '100000000', headcount: 80));
        $this->assertSame('25000000', $big['by']['ipr']['employee_minor']);
        $this->assertSame('1000000', $big['by']['inpp']['employer_minor']);
        // Conditional components are skipped when unused.
        $this->assertArrayNotHasKey('family_allowance', $big['by']);
    }

    public function test_pro_rata_scale_for_weekly_pay(): void
    {
        // Weekly: monthly figures × 12 ÷ 52. Fixed NITA 5 000 → 1 153.846… → 1 154.
        $r = $this->compute(FixturePacks::ke(), new StatutoryInput(gross: '1000000', taxableGross: '1000000', contributoryGross: '1000000', basic: '1000000', scale: (string) BigDecimal::of(12)->dividedBy(52, 12, RoundingMode::HalfUp)));
        $this->assertSame('1154', $r['by']['nita']['employer_minor']);
    }

    public function test_shipped_packs_are_researched_or_still_block(): void
    {
        $on = CarbonImmutable::parse('2026-10-31');
        $all = ['insurance_premiums' => true, 'dependants' => true, 'children' => true, 'pension' => true, 'post_retirement_medical' => true, 'mortgage_interest' => true];

        // CP-01: KE is complete from researched values; CD still lacks the low-confidence INPP and the family allowance
        // (dependants are left out while unconfirmed, never blocking).
        foreach (['KE' => [], 'CD' => ['inpp', 'family_allowance']] as $country => $expected) {
            $pack = app(PayrollPack::class)->of($country);
            $this->assertNotNull($pack);
            $missing = MissingValues::check($pack, $on, 'monthly', $all);
            $this->assertSame($expected, array_column($missing, 'component'), "{$country}: what still blocks runs");

            foreach ($missing as $m) {
                $this->assertContains('needs_confirmation', $m['fields']);
            }

            // Every entered value is researched (with sources) or confirmed; an unconfirmed period ships no figure.
            foreach ($pack['components'] as $component) {
                foreach ($component['periods'] as $period) {
                    if ($period['needs_confirmation']) {
                        foreach ($period as $key => $value) {
                            if (! in_array($key, ['effective_from', 'effective_to', 'needs_confirmation', 'confidence', 'source'], true)) {
                                $this->assertNull($value, "{$country} {$component['code']}.{$key} ships an unconfirmed value");
                            }
                        }

                        continue;
                    }

                    $this->assertSame('researched', $period['status'], "{$country} {$component['code']}");
                    $this->assertContains($period['confidence'], ['high', 'medium']);
                    $this->assertNotEmpty($period['source']);
                }
            }

            $this->assertNull($pack['non_monthly']);
            $this->assertNotEmpty($pack['todo']);
        }

        // Unused conditional components are not required; the fixtures are complete.
        $this->assertSame([], MissingValues::check(FixturePacks::ke(), CarbonImmutable::parse('2026-10-31'), 'monthly', ['insurance_premiums' => true]));
        $this->assertSame([], MissingValues::check(FixturePacks::cd(), CarbonImmutable::parse('2026-10-31'), 'monthly', ['dependants' => true, 'children' => true]));
        $this->assertSame([['component' => 'pack', 'fields' => ['non_monthly']]], MissingValues::check(FixturePacks::ke(), CarbonImmutable::parse('2026-10-31'), 'weekly'));
        // A period not in force yet, and a malformed band list.
        $this->assertSame([['component' => 'paye', 'fields' => ['period']]], array_values(array_filter(
            MissingValues::check(FixturePacks::ke(), CarbonImmutable::parse('2025-12-31'), 'monthly'), fn ($m) => $m['component'] === 'paye')));
        $bad = FixturePacks::ke(['paye' => ['bands' => [['up_to_minor' => null, 'rate' => '10'], ['up_to_minor' => '100', 'rate' => '20']], 'rounding' => ['mode' => 'half_up', 'unit_minor' => '1']]]);
        $this->assertSame([['component' => 'paye', 'fields' => ['bands']]], MissingValues::check($bad, CarbonImmutable::parse('2026-10-31'), 'monthly'));
    }
}
