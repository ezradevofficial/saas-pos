<?php

namespace Tests\Feature\Core\Taxes;

use App\Core\Currency\Money;
use App\Core\Http\ApiException;
use App\Core\MasterData\Taxes\TaxCalculator;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\MasterData\Taxes\TaxRateMissing;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\TestCase;

// MD-03, CP-02: tax on a line, exclusive and inclusive, half-up per line,
// codes add (never compound), effective-dated rates in the company's time
// zone, missing rates and archived codes refused, withholding left out by
// default. The percentages here are synthetic test inputs (12.5, 10, 5,
// 2.5), never real-world rates.
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
        $model = new TaxCode(['code' => $code, 'kind' => $kind, 'name' => $code]);
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
        $this->assertSame(['100000', ['12500'], '112500'], $this->line(Money::ofMinor(100000, 'KES'), [$this->vat('12.5')], false));
    }

    public function test_inclusive_tax_is_backed_out_of_the_gross(): void
    {
        $this->assertSame(['100000', ['12500'], '112500'], $this->line(Money::ofMinor(112500, 'KES'), [$this->vat('12.5')], true));
        // 100000 × 12.5 / 112.5 = 11111.11 → 11111
        $this->assertSame(['88889', ['11111'], '100000'], $this->line(Money::ofMinor(100000, 'KES'), [$this->vat('12.5')], true));
    }

    public function test_cdf_has_no_decimals_and_rounds_half_up_per_line(): void
    {
        // CDF minor unit is the franc (0 decimals).
        // 1000 × 12.5 / 112.5 = 111.11 → 111
        $this->assertSame(['889', ['111'], '1000'], $this->line(Money::ofMinor(1000, 'CDF'), [$this->vat('12.5')], true));
        // 1003 × 12.5 % = 125.375 → 125; 1004 × 12.5 % = 125.5 → 126 (half up).
        $this->assertSame(['1003', ['125'], '1128'], $this->line(Money::ofMinor(1003, 'CDF'), [$this->vat('12.5')], false));
        $this->assertSame(['1004', ['126'], '1130'], $this->line(Money::ofMinor(1004, 'CDF'), [$this->vat('12.5')], false));
        // Exactly half: 100 × 2.5 % = 2.5 → 3.
        $this->assertSame(['100', ['3'], '103'], $this->line(Money::ofMinor(100, 'CDF'), [$this->vat('2.5')], false));
    }

    public function test_a_refund_line_rounds_symmetrically(): void
    {
        $this->assertSame(['-1004', ['-126'], '-1130'], $this->line(Money::ofMinor(-1004, 'CDF'), [$this->vat('12.5')], false));
        $this->assertSame(['-889', ['-111'], '-1000'], $this->line(Money::ofMinor(-1000, 'CDF'), [$this->vat('12.5')], true));
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
        $this->assertSame(['1000', ['0', '125'], '1125'], $this->line(Money::ofMinor(1125, 'KES'), [$exempt, $this->vat('12.5')], true));
    }

    public function test_the_rate_in_force_on_the_date_applies(): void
    {
        $code = $this->code('VAT', 'vat', [['10', '2026-01-01', '2026-06-30'], ['12.5', '2026-07-01']]);

        $this->assertSame(['1000', ['100'], '1100'], $this->line(Money::ofMinor(1000, 'KES'), [$code], false, '2026-06-30'));
        $this->assertSame(['1000', ['125'], '1125'], $this->line(Money::ofMinor(1000, 'KES'), [$code], false, '2026-07-01'));
    }

    public function test_a_missing_rate_is_refused_and_names_the_code(): void
    {
        $needed = $this->code('VAT_STD', 'vat', [[null, '2026-01-01']]);

        try {
            $this->calculator->forLine(Money::ofMinor(1000, 'KES'), [$this->vat('12.5'), $needed], false, $this->on());
            $this->fail('A null rate was used');
        } catch (TaxRateMissing $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('tax_rate_missing', $e->errorCode);
            $this->assertStringContainsString('VAT_STD', $e->getMessage());
            $this->assertSame('VAT_STD', $e->extra['tax_code']);
        }

        // No rate on the date (before the first one) is missing too.
        $this->expectException(TaxRateMissing::class);
        $this->calculator->forLine(Money::ofMinor(1000, 'KES'), [$this->vat('12.5')], false, $this->on('2025-12-31'));
    }

    public function test_a_rate_still_marked_for_confirmation_is_refused(): void
    {
        $unconfirmed = $this->code('WHT', 'withholding', [['2', '2026-01-01', null, true]]);

        $this->expectException(TaxRateMissing::class);
        $this->calculator->forLine(Money::ofMinor(1000, 'KES'), [$unconfirmed], false, $this->on(), includeWithholding: true);
    }

    public function test_the_date_is_the_company_local_date_of_the_instant(): void
    {
        $code = $this->code('VAT', 'vat', [['10', '2026-01-01', '2026-06-30'], ['12.5', '2026-07-01']]);
        $code->setRelation('company', (new Company)->forceFill(['timezone' => 'Africa/Nairobi']));

        // 21:30 UTC on 30 June is 00:30 on 1 July in Nairobi (UTC+3): the new day's rate.
        $result = $this->calculator->forLine(Money::ofMinor(1000, 'KES'), [$code], false, CarbonImmutable::parse('2026-06-30T21:30:00Z'));
        $this->assertSame('125', $result->tax[0]->amount->minor());

        // One second before local midnight: still the old rate.
        $result = $this->calculator->forLine(Money::ofMinor(1000, 'KES'), [$code], false, CarbonImmutable::parse('2026-06-30T20:59:59Z'));
        $this->assertSame('100', $result->tax[0]->amount->minor());
        $this->assertSame('2026-07-01', $code->localDate(CarbonImmutable::parse('2026-06-30T21:30:00Z')));

        // Without a company time zone the instant's UTC date applies.
        $utc = $this->code('VAT', 'vat', [['10', '2026-01-01', '2026-06-30'], ['12.5', '2026-07-01']]);
        $this->assertSame('100', $this->calculator->forLine(Money::ofMinor(1000, 'KES'), [$utc], false, CarbonImmutable::parse('2026-06-30T21:30:00Z'))->tax[0]->amount->minor());
    }

    public function test_withholding_codes_are_left_out_unless_asked_for(): void
    {
        $wht = $this->code('WHT', 'withholding', [['2', '2026-01-01']]);
        $codes = [$this->vat('10'), $wht];

        // Default: withholding is not charged on a sale line.
        $this->assertSame(['1000', ['100'], '1100'], $this->line(Money::ofMinor(1000, 'KES'), $codes, false));

        // Even a withholding code with no confirmed rate does not block the line.
        $needed = $this->code('WHT', 'withholding', [[null, '2026-01-01']]);
        $this->assertSame(['1000', ['100'], '1100'], $this->line(Money::ofMinor(1000, 'KES'), [$this->vat('10'), $needed], false));

        $result = $this->calculator->forLine(Money::ofMinor(1000, 'KES'), $codes, false, $this->on(), includeWithholding: true);
        $this->assertSame([['VAT', '100'], ['WHT', '20']], array_map(fn ($t) => [$t->code, $t->amount->minor()], $result->tax));
        $this->assertSame('1120', $result->gross->minor());
    }

    public function test_an_archived_code_is_refused(): void
    {
        $code = $this->vat('10');
        $code->archived_at = now();

        try {
            $this->calculator->forLine(Money::ofMinor(1000, 'KES'), [$code], false, $this->on());
            $this->fail('An archived code was used');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('tax_code_archived', $e->errorCode);
            $this->assertSame('VAT', $e->extra['tax_code']);
        }
    }

    public function test_no_codes_means_no_tax(): void
    {
        $this->assertSame(['1000', [], '1000'], $this->line(Money::ofMinor(1000, 'KES'), [], true));
        $this->assertSame(['1000', [], '1000'], $this->line(Money::ofMinor(1000, 'KES'), [], false));
    }
}
