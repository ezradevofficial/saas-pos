<?php

namespace Modules\Accounting\Tests;

use Tests\TestCase;

// ACC-01, ACC-07 (stage 7D review #1): every country chart template gives
// each code to one account only, in the pack and in both languages' names
// (a PHP array keeps the last of two equal keys without a word, which is
// how Fixed assets' disposal receivable once took Payroll's 1240), and every
// account a default posting rule or role names exists and is postable.
class ChartCodesTest extends TestCase
{
    private const COUNTRIES = ['KE', 'CD'];

    private static function pack(string $country): array
    {
        return json_decode((string) file_get_contents(base_path("country-packs/{$country}/accounts.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> every account code named under $node (`account` values and the values of `accounts` maps) */
    private static function referenced(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $codes = [];

        foreach ($node as $key => $value) {
            if ($key === 'account' && is_string($value)) {
                $codes[] = $value;
            } elseif ($key === 'accounts' && is_array($value)) {
                $codes = [...$codes, ...array_values(array_filter($value, 'is_string'))];
            } else {
                $codes = [...$codes, ...self::referenced($value)];
            }
        }

        return $codes;
    }

    public function test_each_chart_code_names_one_account(): void
    {
        foreach (self::COUNTRIES as $country) {
            $codes = array_column(self::pack($country)['accounts'], 'code');
            $twice = array_keys(array_filter(array_count_values($codes), fn (int $n) => $n > 1));

            $this->assertSame([], $twice, "{$country}: codes used by more than one account");
        }
    }

    public function test_the_names_of_each_chart_give_each_code_once_in_both_languages(): void
    {
        foreach (['en', 'fr'] as $locale) {
            $source = (string) file_get_contents(base_path("lang/{$locale}/accounting_charts.php"));
            $names = require base_path("lang/{$locale}/accounting_charts.php");

            foreach (self::COUNTRIES as $country) {
                // The country's block in the source text: from `'KE' => [` to the next country or the end.
                $this->assertSame(1, preg_match("/^    '{$country}' => \\[\\n(.*?)^    \\],?\\n/ms", $source, $block), "{$locale}: no {$country} block");
                preg_match_all("/^        '([0-9]+)' =>/m", $block[1], $keys);
                $twice = array_keys(array_filter(array_count_values($keys[1]), fn (int $n) => $n > 1));
                $this->assertSame([], $twice, "{$locale} {$country}: codes named twice");

                foreach (array_column(self::pack($country)['accounts'], 'code') as $code) {
                    $this->assertArrayHasKey($code, $names[$country], "{$locale} {$country}: {$code} has no name");
                }
            }
        }
    }

    public function test_every_account_of_the_default_rules_and_roles_exists_and_is_postable(): void
    {
        foreach (self::COUNTRIES as $country) {
            $pack = self::pack($country);
            $postable = array_column(array_filter($pack['accounts'], fn (array $a) => $a['postable']), 'code');
            $named = array_unique([...self::referenced($pack['posting_rules']), ...array_values($pack['roles'])]);
            $missing = array_values(array_diff($named, $postable));

            $this->assertSame([], $missing, "{$country}: rules or roles name accounts the chart lacks or cannot post to");
        }
    }
}
