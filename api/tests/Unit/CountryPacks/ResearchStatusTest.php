<?php

namespace Tests\Unit\CountryPacks;

use App\Core\CountryPacks\PackFile;
use App\Core\CountryPacks\ResearchStatus;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * CP-01, CP-02: the `researched` value status. Researched values carry
 * their confidence (high or medium), research date and https sources, and
 * are usable; low-confidence values are never entered. The shipped packs
 * of every kind respect these rules.
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
        $this->assertTrue(ResearchStatus::usable(self::RESEARCHED));
        $this->assertTrue(ResearchStatus::usable(['confidence' => 'high'] + self::RESEARCHED));
        // Low confidence is never usable, even if someone marks it researched.
        $this->assertFalse(ResearchStatus::usable(['confidence' => 'low'] + self::RESEARCHED));
        $this->assertFalse(ResearchStatus::usable(['needs_confirmation' => true, 'confidence' => 'low']));

        $this->assertSame(['confidence' => 'medium', 'researched_on' => '2026-10-17', 'source' => ['https://example.org/a'], 'assumed' => []], ResearchStatus::research(self::RESEARCHED));
        $this->assertNull(ResearchStatus::research(['needs_confirmation' => false]));
    }

    public function test_invalid_rows_are_refused(): void
    {
        ResearchStatus::check(self::RESEARCHED, 'ok');
        ResearchStatus::check(['needs_confirmation' => true, 'confidence' => 'low', 'source' => ['https://example.org']], 'low kept for the reader');
        ResearchStatus::check(['reduces_taxable' => false, 'assumed' => ['reduces_taxable']] + self::RESEARCHED, 'assumed');
        // S1: an absent optional field (no ceiling) or a named assumption can be listed.
        ResearchStatus::check(['assumed' => ['base_cap_minor', 'residency']] + self::RESEARCHED, 'assumed absent');

        foreach ([
            'no source' => array_diff_key(self::RESEARCHED, ['source' => 1]),
            'http source' => ['source' => ['http://example.org']] + self::RESEARCHED,
            'low entered' => ['confidence' => 'low'] + self::RESEARCHED,
            'needs confirmation' => ['needs_confirmation' => true] + self::RESEARCHED,
            'bad date' => ['researched_on' => '17/10/2026'] + self::RESEARCHED,
            'unknown status' => ['status' => 'guessed'] + self::RESEARCHED,
            'low value entered' => ['needs_confirmation' => false, 'confidence' => 'low'],
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

    public function test_a_tax_pack_with_a_researched_rate_is_validated(): void
    {
        $pack = ['code' => 'ZZ', 'sources' => [], 'todo' => [], 'tax_codes' => [
            ['code' => 'VAT_STD', 'kind' => 'vat', 'rate' => '16', 'effective_from' => '2026-01-01', 'effective_to' => null, 'fiscal_code' => null] + self::RESEARCHED,
        ]];
        $file = PackFile::fromArray($pack);
        $this->assertSame(['confidence' => 'medium', 'researched_on' => '2026-10-17', 'source' => ['https://example.org/a']], PackFile::research($file->taxCodes()[0]));

        $this->expectException(InvalidArgumentException::class);
        $pack['tax_codes'][0]['confidence'] = 'low';
        PackFile::fromArray($pack);
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

            foreach ($assets['classes'] as $row) {
                ResearchStatus::check($row, "{$country} fixed assets {$row['key']}");
                // Tax depreciation research is low confidence: nothing is entered.
                $this->assertSame([null, null, true], [$row['method'], $row['rate'], $row['needs_confirmation']]);
            }
        }
    }
}
