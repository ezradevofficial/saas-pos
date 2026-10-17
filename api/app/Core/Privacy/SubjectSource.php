<?php

namespace App\Core\Privacy;

use Closure;

/**
 * NFR-08: where one kind of data subject's personal data lives in one
 * table, declared by the module that owns the table (PrivacySubjects).
 *
 * - `match`: the column holding the subject's id (`id` on the subject's
 *   own row; a foreign key such as `party_id` elsewhere).
 * - `primary`: the subject's own row (exactly one per subject type).
 * - `personal`: column => how anonymisation replaces it: `label` (the
 *   anonymised name), `null`, `empty_array`, `empty_object`, `empty_string`,
 *   `anon_email` (a unique address under the reserved `.invalid` domain).
 * - `correctable`: column => validation rules, on the primary source only
 *   (nested rules such as `phones.*.number` are allowed).
 * - `display`: columns that name the subject in lists and pickers.
 * - `after`: run after anonymising (revoke a user's sessions); returns notes.
 */
final class SubjectSource
{
    /**
     * @param  array<string, string>  $personal
     * @param  array<string, list<mixed>>  $correctable
     * @param  list<string>  $display
     * @param  (Closure(string $subjectId): list<string>)|null  $after
     */
    public function __construct(
        public readonly string $subjectType,
        public readonly string $table,
        public readonly string $match,
        public readonly array $personal,
        public readonly bool $primary = false,
        public readonly array $correctable = [],
        public readonly array $display = [],
        public readonly ?Closure $after = null,
        public readonly ?string $companyColumn = 'company_id',
    ) {}

    /** @return list<string> the top-level fields a correction may change */
    public function correctableFields(): array
    {
        return array_values(array_unique(array_map(fn (string $key) => explode('.', $key, 2)[0], array_keys($this->correctable))));
    }
}
