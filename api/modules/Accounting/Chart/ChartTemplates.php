<?php

namespace Modules\Accounting\Chart;

use InvalidArgumentException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSettings;

/**
 * ACC-01: the chart templates, country-pack data
 * (`api/country-packs/{CODE}/accounts.json`, CP-01): account structure and
 * the roles of ACC-02, never tax rates. Names are translated reference
 * data (`lang/{locale}/accounting_charts.php`), written once into a chart.
 * A file is validated when read: codes unique, parents listed before
 * their children, known types, subtypes and cash-flow classes, and every
 * role naming a postable account of a fitting type.
 */
class ChartTemplates
{
    /** @var array<string, array<string, mixed>> */
    private array $read = [];

    /** @return list<string> the country codes with a template */
    public function available(): array
    {
        $codes = [];

        foreach (glob(base_path('country-packs/*/accounts.json')) ?: [] as $file) {
            $codes[] = basename(dirname($file));
        }

        sort($codes);

        return $codes;
    }

    public function exists(string $code): bool
    {
        return preg_match('/^[A-Z]{2}\z/', $code) === 1 && is_file($this->path($code));
    }

    /**
     * @return array{code: string, accounts: list<array{code: string, type: string, subtype: string, parent: ?string, postable: bool, cash_flow: ?string, control?: bool}>, roles: array<string, string>, posting_rules?: array<string, list<array<string, mixed>>>}
     */
    public function get(string $code): array
    {
        if (! $this->exists($code)) {
            throw new InvalidArgumentException("No chart template for [{$code}].");
        }

        return $this->read[$code] ??= $this->validate($code, json_decode((string) file_get_contents($this->path($code)), true, flags: JSON_THROW_ON_ERROR));
    }

    /** The account's name in $locale from the translation files (the code when missing). */
    public function name(string $template, string $code, string $locale): string
    {
        $key = "accounting_charts.{$template}.{$code}";
        $name = __($key, [], $locale);

        return $name === $key ? $code : $name;
    }

    private function path(string $code): string
    {
        return base_path("country-packs/{$code}/accounts.json");
    }

    /** @param array<string, mixed> $data */
    private function validate(string $code, array $data): array
    {
        $fail = fn (string $why) => throw new InvalidArgumentException("Chart template {$code}: {$why}");
        $seen = [];

        if (($data['code'] ?? null) !== $code || ! is_array($data['accounts'] ?? null) || ! is_array($data['roles'] ?? null)) {
            $fail('code, accounts and roles are required.');
        }

        foreach ($data['accounts'] as $account) {
            $accountCode = (string) ($account['code'] ?? '');

            match (true) {
                preg_match('/^[0-9A-Z.\-]{1,20}\z/', $accountCode) !== 1 => $fail("bad code [{$accountCode}]."),
                isset($seen[$accountCode]) => $fail("code {$accountCode} appears twice."),
                ! in_array($account['type'] ?? null, Account::TYPES, true) => $fail("{$accountCode}: unknown type."),
                ! in_array($account['subtype'] ?? null, Account::SUBTYPES, true) => $fail("{$accountCode}: unknown subtype."),
                ($account['parent'] ?? null) !== null && ! isset($seen[$account['parent']]) => $fail("{$accountCode}: its parent must come first."),
                ($account['cash_flow'] ?? null) !== null && ! in_array($account['cash_flow'], Account::CASH_FLOWS, true) => $fail("{$accountCode}: unknown cash-flow class."),
                array_key_exists('rate', $account) || array_key_exists('name', $account) => $fail("{$accountCode}: rates and names do not belong in a chart template."),
                default => null,
            };

            $seen[$accountCode] = $account;
        }

        foreach (AccountingSettings::ROLES as $role) {
            $target = $data['roles'][$role] ?? null;

            if ($target === null || ! isset($seen[$target]) || ! $seen[$target]['postable']) {
                $fail("role {$role} must name a postable account.");
            }
        }

        // ACC-24: default posting rules name accounts of the template by code (structure only).
        foreach ($data['posting_rules'] ?? [] as $type => $lines) {
            if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_.]*\z/', (string) $type) !== 1 || ! is_array($lines) || count($lines) < 2) {
                $fail("posting rule {$type}: a type and at least two lines are required.");
            }

            foreach ($lines as $line) {
                $codes = [...(isset($line['account']) ? [$line['account']] : []), ...array_values((array) ($line['accounts'] ?? []))];

                if (! in_array($line['side'] ?? null, ['debit', 'credit'], true) || preg_match('/^[A-Za-z0-9_.\-]+(\.\*)?\z/', (string) ($line['field'] ?? '')) !== 1
                    || ($codes === [] && ! str_ends_with((string) $line['field'], '.*'))) {
                    $fail("posting rule {$type}: bad line.");
                }

                foreach ($codes as $target) {
                    if (! isset($seen[$target]) || ! $seen[$target]['postable']) {
                        $fail("posting rule {$type}: {$target} must be a postable account.");
                    }
                }
            }
        }

        return $data;
    }
}
