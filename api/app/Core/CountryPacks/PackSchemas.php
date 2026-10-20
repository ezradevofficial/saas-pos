<?php

namespace App\Core\CountryPacks;

use App\Core\Workflow\Calendar\HolidayFile;
use Closure;
use InvalidArgumentException;

/**
 * ADM-04b (ADR 022): what each pack kind looks like. The core knows tax
 * (PackFile) and holidays (HolidayFile); each module registers the
 * schema of its own kind (payroll, hr, fixed_assets, accounts) with:
 *
 * - `check`: validates the document's structure, throwing
 *   InvalidArgumentException with the reason;
 * - `entries`: the collections a diff compares, path => key fields
 *   (`tax_codes` by code and effective_from, `components` by code);
 * - `periods`: the collections whose rows are periods of one key, path =>
 *   key field, checked for overlaps;
 * - `required`: keys every row of a collection must carry, path => keys
 *   (`components.*.periods` for nested rows); an omitted key is an error
 *   (review L7), a null one is judged by PackValidator.
 */
class PackSchemas
{
    /** @var array<string, array{check: Closure(array<string, mixed>): void, entries: array<string, list<string>>, periods: array<string, ?string>, required: array<string, list<string>>}> */
    private array $schemas = [];

    public function __construct()
    {
        $this->register('tax', fn (array $content) => PackFile::fromArray($content), ['tax_codes' => ['code', 'effective_from']], ['tax_codes' => 'code'],
            ['tax_codes' => ['code', 'kind', 'rate', 'needs_confirmation', 'effective_from', 'effective_to', 'fiscal_code']]);
        $this->register('holidays', fn (array $content) => HolidayFile::fromArray($content), ['holidays' => ['key']], [], ['holidays' => ['key']]);
    }

    /**
     * @param  Closure(array<string, mixed>): mixed  $check
     * @param  array<string, list<string>>  $entries
     * @param  array<string, ?string>  $periods
     * @param  array<string, list<string>>  $required
     */
    public function register(string $kind, Closure $check, array $entries, array $periods, array $required = []): void
    {
        if (! isset(PackDocuments::KINDS[$kind])) {
            throw new InvalidArgumentException("Unknown pack kind [{$kind}].");
        }

        $this->schemas[$kind] = ['check' => $check, 'entries' => $entries, 'periods' => $periods, 'required' => $required];
    }

    /** @return array{check: Closure(array<string, mixed>): void, entries: array<string, list<string>>, periods: array<string, ?string>, required: array<string, list<string>>}|null */
    public function get(string $kind): ?array
    {
        return $this->schemas[$kind] ?? null;
    }
}
