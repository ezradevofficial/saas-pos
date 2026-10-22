<?php

namespace Tests\Unit\CountryPacks;

use App\Core\CountryPacks\PackFile;
use App\Core\CountryPacks\PackValidator;
use App\Core\CountryPacks\ResearchStatus;
use InvalidArgumentException;
use Modules\FixedAssets\TaxBook\FixedAssetsPackSchema;
use Modules\FixedAssets\TaxBook\TaxClasses;
use Modules\HR\Support\HrPackSchema;
use Modules\Payroll\Statutory\PayrollPackSchema;
use Tests\TestCase;

/**
 * CP-01, CP-02: the `researched` value status. Researched values carry
 * their confidence (high, medium or low: owner decision 2026-10-22),
 * research date and https sources, and are usable; a low one is flagged
 * (ResearchStatus::low) for the stronger notice and the release warning.
 * The shipped packs of every kind respect these rules.
 */
class ResearchStatusTest extends TestCase
{
    private const RESEARCHED = ['status' => 'researched', 'confidence' => 'medium', 'researched_on' => '2026-10-17', 'source' => ['https://example.org/a'], 'needs_confirmation' => false];

    public function test_status_and_usability(): void
    {
        $this->assertSame('confirmed', ResearchStatus::of(['needs_confirmation' => false]));
        $this->assertSame('needs_confirmation', ResearchStatus::of(['needs_confirmation' => true]));
        $this->assertSame('researched', ResearchStatus::of(self::RESEARCHED));

        $this->assertTrue(ResearchStatus::usable(['needs_confirmation' => false]));
        // S2: validation needs an explicit status; run time reads an older row from needs_confirmation.
        foreach ([['needs_confirmation' => false], ['needs_confirmation' => true]] as $row) {
            try {
                ResearchStatus::check($row, 'no status');
                $this->fail('a row without status was accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertTrue(ResearchStatus::usable(self::RESEARCHED));
        $this->assertTrue(ResearchStatus::usable(['confidence' => 'high'] + self::RESEARCHED));
        // Owner decision 2026-10-22: a low-confidence researched row is usable, and flagged low.
        $this->assertTrue(ResearchStatus::usable(['confidence' => 'low'] + self::RESEARCHED));
        $this->assertTrue(ResearchStatus::low(['confidence' => 'low'] + self::RESEARCHED));
        $this->assertFalse(ResearchStatus::low(self::RESEARCHED));
        $this->assertTrue(ResearchStatus::lowResearch(ResearchStatus::research(['confidence' => 'low'] + self::RESEARCHED)));
        // Research that was not entered stays unusable.
        $this->assertFalse(ResearchStatus::usable(['needs_confirmation' => true, 'confidence' => 'low']));
        $this->assertFalse(ResearchStatus::low(['status' => 'needs_confirmation', 'needs_confirmation' => true, 'confidence' => 'low']));

        $this->assertSame(['confidence' => 'medium', 'researched_on' => '2026-10-17', 'source' => ['https://example.org/a'], 'assumed' => []], ResearchStatus::research(self::RESEARCHED));
        $this->assertNull(ResearchStatus::research(['needs_confirmation' => false]));
    }

    public function test_invalid_rows_are_refused(): void
    {
        ResearchStatus::check(self::RESEARCHED, 'ok');
        ResearchStatus::check(['confidence' => 'low'] + self::RESEARCHED, 'low researched');
        ResearchStatus::check(['status' => 'needs_confirmation', 'needs_confirmation' => true, 'confidence' => 'low', 'source' => ['https://example.org']], 'low kept for the reader');
        ResearchStatus::check(['reduces_taxable' => false, 'assumed' => ['reduces_taxable']] + self::RESEARCHED, 'assumed');
        // S1: an absent optional field (no ceiling) or a named assumption can be listed.
        ResearchStatus::check(['assumed' => ['base_cap_minor', 'residency']] + self::RESEARCHED, 'assumed absent');

        foreach ([
            'no source' => array_diff_key(self::RESEARCHED, ['source' => 1]),
            'http source' => ['source' => ['http://example.org']] + self::RESEARCHED,
            'no confidence' => array_diff_key(self::RESEARCHED, ['confidence' => 1]),
            'needs confirmation' => ['needs_confirmation' => true] + self::RESEARCHED,
            'bad date' => ['researched_on' => '17/10/2026'] + self::RESEARCHED,
            'unknown status' => ['status' => 'guessed'] + self::RESEARCHED,
            'low value entered' => ['status' => 'confirmed', 'needs_confirmation' => false, 'confidence' => 'low'],
            'assumed bad name' => ['assumed' => ['Reduces taxable']] + self::RESEARCHED,
            'assumed on confirmed' => ['needs_confirmation' => false, 'status' => 'confirmed', 'rounding' => 1, 'assumed' => ['rounding']],
        ] as $case => $row) {
            try {
                ResearchStatus::check($row, $case);
                $this->fail("{$case} was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_fixed_asset_classes_go_through_the_research_status(): void
    {
        $class = ['key' => 'computers', 'method' => 'reducing_balance', 'rate' => '25', 'needs_confirmation' => false];
        $this->assertTrue(TaxClasses::confirmed(['status' => 'confirmed'] + $class));
        $this->assertTrue(TaxClasses::confirmed(['status' => 'researched', 'confidence' => 'medium'] + $class));
        // Owner decision 2026-10-22: low confidence is computed from too.
        $this->assertTrue(TaxClasses::confirmed(['status' => 'researched', 'confidence' => 'low'] + $class));
        $this->assertFalse(TaxClasses::confirmed(['needs_confirmation' => true] + $class));
    }

    public function test_a_tax_pack_with_a_researched_rate_is_validated(): void
    {
        $pack = ['code' => 'ZZ', 'sources' => [], 'todo' => [], 'tax_codes' => [
            ['code' => 'VAT_STD', 'kind' => 'vat', 'rate' => '16', 'effective_from' => '2026-01-01', 'effective_to' => null, 'fiscal_code' => null] + self::RESEARCHED,
        ]];
        $file = PackFile::fromArray($pack);
        $this->assertSame(['confidence' => 'medium', 'researched_on' => '2026-10-17', 'source' => ['https://example.org/a']], PackFile::research($file->taxCodes()[0]));

        $pack['tax_codes'][0]['confidence'] = 'low';
        $this->assertSame('low', PackFile::research(PackFile::fromArray($pack)->taxCodes()[0])['confidence']);

        $this->expectException(InvalidArgumentException::class);
        $pack['tax_codes'][0]['confidence'] = 'certain';
        PackFile::fromArray($pack);
    }

    public function test_the_release_validator_warns_on_every_low_confidence_row(): void
    {
        $validator = app(PackValidator::class);
        $read = fn (string $country, string $kind) => json_decode((string) file_get_contents(base_path("country-packs/{$country}/{$kind}.json")), true, 32, JSON_THROW_ON_ERROR);
        $low = fn (array $result) => array_values(array_filter($result['warnings'], fn (string $w) => str_contains($w, 'low confidence')));

        // CD payroll: IPR (minimum before the cap), its dependants reduction, CNSS (deducted from the IPR base), INPP.
        $cd = $validator->validate('CD', 'payroll', $read('CD', 'payroll'));
        $this->assertSame([], $cd['errors']);
        $this->assertCount(4, $low($cd));
        $this->assertNotEmpty(array_filter($low($cd), fn (string $w) => str_starts_with($w, 'components.inpp')));
        // CD HR: annual leave and maternity.
        $this->assertCount(2, $low($validator->validate('CD', 'hr', $read('CD', 'hr'))));
        // KE fixed assets: the eight classes and the year-of-acquisition rule.
        $ke = $validator->validate('KE', 'fixed_assets', $read('KE', 'fixed_assets'));
        $this->assertSame([], $ke['errors']);
        $this->assertCount(9, $low($ke));
        // Medium and high rows give no such warning.
        $this->assertSame([], $low($validator->validate('KE', 'payroll', $read('KE', 'payroll'))));
        $this->assertSame([], $low($validator->validate('KE', 'tax', $read('KE', 'pack'))));
    }

    public function test_the_shipped_packs_respect_the_status_rules(): void
    {
        foreach (['KE', 'CD'] as $country) {
            foreach (PackFile::read(PackFile::path($country))->taxCodes() as $row) {
                ResearchStatus::check($row, "{$country} pack {$row['code']}");
            }

            $payroll = json_decode((string) file_get_contents(base_path("country-packs/{$country}/payroll.json")), true, 32, JSON_THROW_ON_ERROR);

            foreach ($payroll['components'] as $component) {
                foreach ($component['periods'] as $period) {
                    ResearchStatus::check($period, "{$country} payroll {$component['code']} {$period['effective_from']}");
                }
            }

            $hr = json_decode((string) file_get_contents(base_path("country-packs/{$country}/hr.json")), true, 16, JSON_THROW_ON_ERROR);

            foreach ([...$hr['identifier_types'], ...$hr['statutory_leave_types']] as $row) {
                ResearchStatus::check($row, "{$country} hr {$row['key']}");
            }

            $assets = json_decode((string) file_get_contents(base_path("country-packs/{$country}/fixed_assets.json")), true, 16, JSON_THROW_ON_ERROR);

            FixedAssetsPackSchema::check($assets);
            PayrollPackSchema::check($payroll);
            HrPackSchema::check($hr);

            foreach ($assets['classes'] as $row) {
                ResearchStatus::check($row, "{$country} fixed assets {$row['key']}");
                // KE: the Second Schedule classes, researched at low confidence (owner decision 2026-10-22).
                // CD: no statutory table was found, so nothing is entered.
                $this->assertSame($country === 'KE' ? ['straight_line', false, 'low'] : [null, true, null], [$row['method'], $row['needs_confirmation'], $row['confidence'] ?? null]);
            }
        }
    }
}
