<?php

namespace Tests\Feature\Core\Currency;

use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\Rules\MoneyAmount;
use App\Core\Currency\TenantCurrencies;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// ADR 003: money typed in major units, validated against the currency's decimals.
class MoneyAmountRuleTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    /** @return array<string, list<string>> */
    private function errors(array $data, MoneyAmount $rule): array
    {
        return Validator::make($data, ['amount' => ['required', $rule]])->errors()->toArray();
    }

    public function test_decimal_strings_within_the_currency_decimals_pass(): void
    {
        foreach ([['12450.50', 'KES'], ['12450', 'KES'], ['-3.5', 'USD'], ['135000', 'CDF'], ['135000.00', 'CDF'], [250, 'CDF']] as [$amount, $code]) {
            $this->assertSame([], $this->errors(['amount' => $amount], MoneyAmount::in($code)), "{$amount} {$code}");
        }
    }

    public function test_too_many_decimals_for_the_currency_fail(): void
    {
        $this->assertSame(
            ['amount' => ['The amount can have at most 0 decimals in CDF.']],
            $this->errors(['amount' => '135000.5'], MoneyAmount::in('CDF')),
        );
        $this->assertArrayHasKey('amount', $this->errors(['amount' => '1.005'], MoneyAmount::in('KES')));
        $this->assertSame([], $this->errors(['amount' => '1.005'], MoneyAmount::in('BHD')));
    }

    public function test_non_decimal_input_fails_floats_included(): void
    {
        foreach (['abc', '1,000', '1e3', '.5', '5.', ' 5', 12.5, true, ['1']] as $amount) {
            $this->assertArrayHasKey('amount', $this->errors(['amount' => $amount], MoneyAmount::in('KES')), json_encode($amount));
        }
    }

    public function test_a_trailing_newline_fails(): void
    {
        // `$` would match before a final newline; the pattern ends at \z.
        foreach (["5\n", "12450.50\n", "-3\n"] as $amount) {
            $this->assertArrayHasKey('amount', $this->errors(['amount' => $amount], MoneyAmount::in('KES')), json_encode($amount));
        }
    }

    public function test_the_currency_can_come_from_another_field(): void
    {
        $rule = fn () => MoneyAmount::fromField('payment.currency');

        $this->assertSame([], $this->errors(['amount' => '10.25', 'payment' => ['currency' => 'USD']], $rule()));
        $this->assertArrayHasKey('amount', $this->errors(['amount' => '10.25', 'payment' => ['currency' => 'CDF']], $rule()));
        // A missing currency is reported by that field's own rules, not here.
        $this->assertSame([], $this->errors(['amount' => '10.25'], $rule()));
    }

    public function test_min_and_max(): void
    {
        $rule = fn () => MoneyAmount::in('KES')->min('0.01')->max('1000');

        $this->assertSame([], $this->errors(['amount' => '0.01'], $rule()));
        $this->assertSame([], $this->errors(['amount' => '1000.00'], $rule()));
        $this->assertSame(['amount' => ['The amount must be at least KES 0.01.']], $this->errors(['amount' => '0'], $rule()));
        $this->assertSame(['amount' => ['The amount must be at most KES 1000.']], $this->errors(['amount' => '1000.01'], $rule()));
    }

    public function test_the_tenant_decimals_apply(): void
    {
        $this->setUpOrganisation();

        $this->inTenant(function () {
            $usd = app(TenantCurrencies::class)->activate('USD');
            $usd->decimals = 0;
            $usd->save();

            $this->assertArrayHasKey('amount', $this->errors(['amount' => '1.50'], MoneyAmount::in('USD')));
            $this->assertSame(1, TenantCurrency::count());
        });
    }

    public function test_messages_are_translated(): void
    {
        app()->setLocale('fr');

        $this->assertSame(
            ['amount' => ['Le champ amount peut avoir au plus 0 décimales en CDF.']],
            $this->errors(['amount' => '1.5'], MoneyAmount::in('CDF')),
        );
    }
}
