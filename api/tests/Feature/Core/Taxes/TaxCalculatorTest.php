<?php

namespace Tests\Feature\Core\Taxes;

use App\Core\Currency\Money;
use App\Core\MasterData\Taxes\TaxCalculator;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\MasterData\Taxes\TaxRateMissing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\TestCase;

// MD-03, CP-02: tax on a line, exclusive and inclusive, half-up per line,
// codes add (never compound), effective-dated rates, missing rates refused.
// The percentages here are test inputs, not real-world rates.
class TaxCalculatorTest extends TestCase
{
    private TaxCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new TaxCalculator;
    }

    /** @param list<array{0: ?string, 1: string, 2?: ?string, 3?: bool}> $rates [rate, from, to, needs_confirmation] */
    private function code(string $code, string $kind, array $rates = []): TaxCode
    {
        $model = new TaxCode(['code' => $code, 'kind' => $kind, 'name_en' => $code, 'name_fr' => $code]);
        $model->id = (string) Str::uuid7();
        $model->setRelation('rates', collect(array_map(fn (array $r) => new TaxRate([
            'rate' => $r[0],
            'effective_from' => $r[1],
            'effective_to' => $r[2] ?? null,
            'needs_confirmation' => $r[3] ?? ($r[0] === null),
        ]), $rates)));

        return $model;
    }

    private function vat(string $rate, string $code = 'VAT'): TaxCode
    {
        return $this->code($code, 'vat', [[$rate, '2026-01-01']]);
    }

    private function on(string $date = '2026-10-08'): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    /** @return array{0: string, 1: list<string>, 2: string} net, taxes, gross in minor units */
    private function line(Money $amount, array $codes, bool $inclusive, string $date = '2026-10-08'): array
    {
        $result = $this->calculator->forLine($amount, $codes, $inclusive, $this->on($date));
        $taxes = array_map(fn ($t) => $t->amount->minor(), $result->tax);

        // net + taxes = gross, exactly, in the line's currency.
        $this->assertTrue($result->net->plus($result->totalTax())->equals($result->gross));
        $this->assertSame($amount->currency(), $result->gross->currency());

        return [$result->net->minor(), $taxes, $result->gross->minor()];
    }

    public function test_exclusive_tax_is_added_to_the_net(): void
    {
        $this->assertSame(['100000', ['16000'], '116000'], $this->line(Money::ofMinor(100000, 'KES'), [$this->vat('16')], false));
    }

    public function test_inclusive_tax_is_backed_out_of_the_gross(): void
    {
        $this->assertSame(['100000', ['16000'], '116000'], $this->line(Money::ofMinor(116000, 'KES'), [$this->vat('16')], true));
        // 99999 × 16 / 116 = 13792.97 → 13793
        $this->assertSame(['86206', ['13793'], '99999'], $this->line(Money::ofMinor(99999, 'KES'), [$this->vat('16')], true));
    }

    public function test_cdf_has_no_decimals_and_rounds_half_up_per_line(): void
    {
        // CDF minor unit is the franc (0 decimals).
        $this->assertSame(['862', ['138'], '1000'], $this->line(Money::ofMinor(1000, 'CDF'), [$this->vat('16')], true));
        $this->assertSame(['1003', ['160'], '1163'], $this->line(Money::ofMinor(1003, 'CDF'), [$this->vat('16')], false));
        $this->assertSame(['1004', ['161'], '1165'], $this->line(Money::ofMinor(1004, 'CDF'), [$this->vat('16')], false));
        // Exactly half: 100 × 2.5 % = 2.5 → 3.
        $this->assertSame(['100', ['3'], '103'], $this->line(Money::ofMinor(100, 'CDF'), [$this->vat('2.5')], false));
    }

    public function test_a_refund_line_rounds_symmetrically(): void
    {
        $this->assertSame(['-1004', ['-161'], '-1165'], $this->line(Money::ofMinor(-1004, 'CDF'), [$this->vat('16')], false));
        $this->assertSame(['-862', ['-138'], '-1000'], $this->line(Money::ofMinor(-1000, 'CDF'), [$this->vat('16')], true));
    }

    public function test_several_codes_add_and_never_compound(): void
    {
        $codes = [$this->vat('10', 'A'), $this->vat('5', 'B')];

        // Exclusive: each on the net (compounding would give 55 for B).
        $this->assertSame(['1000', ['100', '50'], '1150'], $this->line(Money::ofMinor(1000, 'KES'), $codes, false));
        // Inclusive: 1150 × 15 / 115 = 150, split 2:1.
        $this->assertSame(['1000', ['100', '50'], '1150'], $this->line(Money::ofMinor(1150, 'KES'), $codes, true));
        // 1000 × 15 / 115 = 130.43 → 130, split 2:1 = 86.67 / 43.33 → 87 / 43 (nothing lost).
        $this->assertSame(['870', ['87', '43'], '1000'], $this->line(Money::ofMinor(1000, 'KES'), $codes, true));
    }

    public function test_exempt_and_zero_rated_codes_add_no_tax(): void
    {
        $exempt = $this->code('EXEMPT', 'exempt');
        $zero = $this->code('ZERO', 'zero_rated', [['0', '2026-01-01']]);

        $result = $this->calculator->forLine(Money::ofMinor(5000, 'USD'), [$exempt, $zero], true, $this->on());
        $this->assertSame(['5000', '5000'], [$result->net->minor(), $result->gross->minor()]);
        $this->assertNull($result->tax[0]->rate);
        $this->assertSame('0.0000', $result->tax[1]->rate);
        $this->assertSame(['0', '0'], [$result->tax[0]->amount->minor(), $result->tax[1]->amount->minor()]);

        // Next to a taxed code, the exempt one takes no share.
        $this->assertSame(['1000', ['0', '160'], '1160'], $this->line(Money::ofMinor(1160, 'KES'), [$exempt, $this->vat('16')], true));
    }

    public function test_the_rate_in_force_on_the_date_applies(): void
    {
        $code = $this->code('VAT', 'vat', [['14', '2026-01-01', '2026-06-30'], ['16', '2026-07-01']]);

        $this->assertSame(['1000', ['140'], '1140'], $this->line(Money::ofMinor(1000, 'KES'), [$code], false, '2026-06-30'));
        $this->assertSame(['1000', ['160'], '1160'], $this->line(Money::ofMinor(1000, 'KES'), [$code], false, '2026-07-01'));
    }

    public function test_a_missing_rate_is_refused_and_names_the_code(): void
    {
        $needed = $this->code('VAT_STD', 'vat', [[null, '2026-01-01']]);

        try {
            $this->calculator->forLine(Money::ofMinor(1000, 'KES'), [$this->vat('16'), $needed], false, $this->on());
            $this->fail('A null rate was used');
        } catch (TaxRateMissing $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('tax_rate_missing', $e->errorCode);
            $this->assertStringContainsString('VAT_STD', $e->getMessage());
            $this->assertSame('VAT_STD', $e->extra['tax_code']);
        }

        // No rate on the date (before the first one) is missing too.
        $this->expectException(TaxRateMissing::class);
        $this->calculator->forLine(Money::ofMinor(1000, 'KES'), [$this->vat('16')], false, $this->on('2025-12-31'));
    }

    public function test_a_rate_still_marked_for_confirmation_is_refused(): void
    {
        $unconfirmed = $this->code('WHT', 'withholding', [['2', '2026-01-01', null, true]]);

        $this->expectException(TaxRateMissing::class);
        $this->calculator->forLine(Money::ofMinor(1000, 'KES'), [$unconfirmed], false, $this->on());
    }

    public function test_no_codes_means_no_tax(): void
    {
        $this->assertSame(['1000', [], '1000'], $this->line(Money::ofMinor(1000, 'KES'), [], true));
        $this->assertSame(['1000', [], '1000'], $this->line(Money::ofMinor(1000, 'KES'), [], false));
    }
}
