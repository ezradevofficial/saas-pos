<?php

namespace App\Core\CountryPacks;

use InvalidArgumentException;
use JsonException;

/**
 * ADM-04b (ADR 022): validation and diff of a pack release before anyone
 * approves it.
 *
 * Errors (block approval):
 * - the document's `code` is not the release's country;
 * - the kind's schema refuses it (PackSchemas);
 * - two periods of one key overlap;
 * - a missing required value: a row marked confirmed
 *   (`needs_confirmation: false`) with an empty (null) figure.
 *
 * Warnings (shown, not blocking): rows still marked as needing
 * confirmation; their empty figures keep blocking what uses them at
 * runtime ("Rate needed"), as the shipped packs do.
 *
 * The diff lists the entries added, removed and changed against the pack
 * in force, by the kind's keys (`tax_codes:VAT_STD@2026-01-01`), and the
 * top-level settings that changed.
 */
class PackValidator
{
    /** Fields that may be null in a confirmed row (they mean "none", not "unknown"). */
    private const NULLABLE = ['effective_to', 'pattern', 'layout', 'parent', 'cash_flow', 'fiscal_code', 'notes', 'when', 'applies_to', 'base'];

    /** Top-level keys that are commentary, not pack data. */
    private const COMMENTARY = ['notes', 'sources', 'todo'];

    public function __construct(private readonly PackSchemas $schemas) {}

    /**
     * @param  array<string, mixed>  $content
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(string $country, string $kind, array $content): array
    {
        $errors = [];
        $warnings = [];
        $schema = $this->schemas->get($kind);

        if (($content['code'] ?? null) !== $country) {
            $errors[] = __('country_packs.releases.wrong_country', ['code' => (string) ($content['code'] ?? ''), 'country' => $country]);
        }

        if ($schema === null) {
            $errors[] = __('country_packs.releases.unknown_kind', ['kind' => $kind]);

            return ['errors' => $errors, 'warnings' => $warnings];
        }

        try {
            ($schema['check'])($content);
        } catch (InvalidArgumentException|JsonException $e) {
            $errors[] = __('country_packs.releases.schema', ['reason' => $e->getMessage()]);
        }

        foreach ($schema['periods'] as $path => $keyField) {
            foreach ($this->groups($content, $path, $keyField) as $key => $rows) {
                if (self::overlap($rows)) {
                    $errors[] = __('country_packs.releases.overlap', ['path' => $path, 'key' => $key]);
                }
            }
        }

        // Review L7: a row that leaves out a key its kind requires.
        foreach ($schema['required'] ?? [] as $path => $keys) {
            foreach ($this->groups($content, $path, null) as $group => $rows) {
                foreach ($rows as $row) {
                    $missing = array_values(array_diff($keys, array_keys($row)));

                    if ($missing !== []) {
                        $errors[] = __('country_packs.releases.missing_key', ['path' => $path, 'key' => (string) ($row['code'] ?? $row['key'] ?? $group), 'fields' => implode(', ', $missing)]);
                    }
                }
            }
        }

        $this->walk($kind, $content, '', $errors, $warnings);

        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @param  array<string, mixed>|null  $base  the pack in force (null: none)
     * @param  array<string, mixed>  $content
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}
     */
    public function diff(string $kind, ?array $base, array $content): array
    {
        $schema = $this->schemas->get($kind);
        $diff = ['added' => [], 'removed' => [], 'changed' => []];

        foreach ($schema['entries'] ?? [] as $path => $fields) {
            $before = $this->index((array) ($base[$path] ?? []), $fields);
            $after = $this->index((array) ($content[$path] ?? []), $fields);

            foreach ($after as $key => $row) {
                if (! array_key_exists($key, $before)) {
                    $diff['added'][] = "{$path}:{$key}";
                } elseif ($before[$key] !== $row) {
                    $diff['changed'][] = "{$path}:{$key}";
                }
            }

            foreach (array_keys(array_diff_key($before, $after)) as $key) {
                $diff['removed'][] = "{$path}:{$key}";
            }
        }

        $settings = array_diff(array_unique([...array_keys($base ?? []), ...array_keys($content)]), [...array_keys($schema['entries'] ?? []), ...self::COMMENTARY, 'code']);

        foreach ($settings as $key) {
            if (self::canonical($base[$key] ?? null) !== self::canonical($content[$key] ?? null)) {
                $diff['changed'][] = "settings:{$key}";
            }
        }

        foreach ($diff as &$list) {
            sort($list);
        }

        return $diff;
    }

    /**
     * Rows of $path grouped by key: `tax_codes` by `code`, or `components.*.periods` (null key field: one group per parent).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function groups(array $content, string $path, ?string $keyField): array
    {
        if (str_contains($path, '.*.')) {
            [$parent, $child] = explode('.*.', $path, 2);
            $groups = [];

            foreach ((array) ($content[$parent] ?? []) as $i => $row) {
                $groups[(string) ($row['code'] ?? $row['key'] ?? $i)] = array_values(array_filter((array) ($row[$child] ?? []), 'is_array'));
            }

            return $groups;
        }

        $groups = [];

        foreach ((array) ($content[$path] ?? []) as $i => $row) {
            if (is_array($row)) {
                $groups[$keyField === null ? (string) $i : (string) ($row[$keyField] ?? '')][] = $row;
            }
        }

        return $groups;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function overlap(array $rows): bool
    {
        foreach ($rows as $i => $a) {
            foreach (array_slice($rows, $i + 1) as $b) {
                $aFrom = (string) ($a['effective_from'] ?? '0000-01-01');
                $bFrom = (string) ($b['effective_from'] ?? '0000-01-01');
                $aTo = (string) ($a['effective_to'] ?? '9999-12-31');
                $bTo = (string) ($b['effective_to'] ?? '9999-12-31');

                if ($aFrom <= $bTo && $bFrom <= $aTo) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Missing values: a confirmed row with an empty figure is an error; an
     * unconfirmed one is a warning.
     *
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function walk(string $kind, mixed $node, string $path, array &$errors, array &$warnings): void
    {
        if (! is_array($node)) {
            return;
        }

        if (array_key_exists('needs_confirmation', $node)) {
            $empty = [];

            foreach ($node as $key => $value) {
                $exempt = ($kind === 'tax' && $key === 'rate' && ($node['kind'] ?? null) === 'exempt')
                    // CP-01: a leave type's null pay is "pay rate not confirmed" (warned below); its days stand.
                    || ($kind === 'hr' && $key === 'paid');

                if ($value === null && ! in_array($key, self::NULLABLE, true) && ! $exempt) {
                    $empty[] = $key;
                }
            }

            $where = $path === '' ? '(top)' : $path;

            if ($kind === 'hr' && array_key_exists('paid', $node) && $node['paid'] === null && $node['needs_confirmation'] === false) {
                $warnings[] = __('country_packs.releases.pay_needs_confirmation', ['path' => $path === '' ? '(top)' : $path]);
            }

            if ($node['needs_confirmation'] === false && $empty !== []) {
                $errors[] = __('country_packs.releases.missing_value', ['path' => $where, 'fields' => implode(', ', $empty)]);
            } elseif ($node['needs_confirmation'] === true) {
                $warnings[] = __('country_packs.releases.needs_confirmation', ['path' => $where]);
            }
        }

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $label = is_array($child) && (isset($child['code']) || isset($child['key'])) ? (string) ($child['code'] ?? $child['key']) : (string) $key;
                $this->walk($kind, $child, $path === '' ? $label : "{$path}.{$label}", $errors, $warnings);
            }
        }
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<string>  $fields
     * @return array<string, string> key => canonical row
     */
    private function index(array $rows, array $fields): array
    {
        $index = [];

        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = implode('@', array_map(fn (string $f) => (string) ($row[$f] ?? ''), $fields)) ?: (string) $i;
            $index[$key] = self::canonical($row);
        }

        return $index;
    }

    private static function canonical(mixed $value): string
    {
        if (is_array($value) && ! array_is_list($value)) {
            ksort($value);
        }

        if (is_array($value)) {
            $value = array_map(fn ($v) => is_array($v) ? json_decode(self::canonical($v), true) : $v, $value);
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
