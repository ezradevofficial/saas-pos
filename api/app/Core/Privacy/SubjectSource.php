<?php

namespace App\Core\Privacy;

use Closure;
use Illuminate\Database\Query\Builder;

/**
 * NFR-08: one table that holds rows about a data subject, declared by the
 * module that owns the table (PrivacySubjects). Only declared tables are
 * ever read or changed: nothing is found by following foreign keys, so a
 * row about someone else (a sale the subject rang up as cashier, a lead
 * they own, an appraisal they wrote) never enters their report (review H1).
 *
 * - `match`: the column naming the subject (`id` on the subject's own row,
 *   `party_id`, `customer_id`, `employee_id`, `user_id`...). With
 *   `through` = [table, column, key], rows whose `match` is the `key` of a
 *   `table` row whose `column` is the subject (a storefront account's
 *   addresses through the account). `matcher` filters the query itself
 *   when neither fits.
 * - `primary`: the subject's own row (exactly one per subject type).
 * - `related`: business records about the subject (purchases, leave,
 *   payslips): in the access report, never anonymised, listed as retained.
 * - `personal`: column => how anonymisation replaces it: `label`, `null`,
 *   `empty_array`, `empty_object`, `empty_string`, `anon_email`.
 * - `correctable`: column => validation rules, on the primary source only
 *   (nested rules such as `phones.*.number` are allowed).
 * - `display`: columns that name the subject in lists and pickers.
 * - `after`: run after anonymising (revoke a user's sessions); returns notes.
 * - `report`: false for tables that hold only credentials or codes.
 * - `fieldRules`: the RBAC-05 resource whose field rules apply to this
 *   table's rows in an access report (Phase 8 review M7), with
 *   `fieldColumns` naming the columns a rule's field stands for where they
 *   differ (a party's `credit_limit_minor` hides a change's limits); a
 *   field naming a column hides that column, `custom.<key>` that key of
 *   the `custom` column.
 * - `subjects`: on the primary source, which rows are data subjects at all
 *   (Phase 8 review L2: API-key, app and support principals are users rows
 *   but not people); others are never found, searched or changed.
 */
final class SubjectSource
{
    /**
     * @param  array<string, string>  $personal
     * @param  array<string, list<mixed>>  $correctable
     * @param  list<string>  $display
     * @param  (Closure(string $subjectId): list<string>)|null  $after
     * @param  array{0: string, 1: string, 2?: string}|null  $through
     * @param  (Closure(Builder $query, string $subjectId): void)|null  $matcher
     * @param  array<string, list<string>>  $fieldColumns
     * @param  (Closure(Builder $query): void)|null  $subjects
     */
    public function __construct(
        public readonly string $subjectType,
        public readonly string $table,
        public readonly string $match,
        public readonly array $personal = [],
        public readonly bool $primary = false,
        public readonly array $correctable = [],
        public readonly array $display = [],
        public readonly ?Closure $after = null,
        public readonly ?string $companyColumn = 'company_id',
        public readonly bool $related = false,
        public readonly ?array $through = null,
        public readonly ?Closure $matcher = null,
        public readonly bool $report = true,
        public readonly ?string $fieldRules = null,
        public readonly array $fieldColumns = [],
        public readonly ?Closure $subjects = null,
    ) {}

    /** Limit $query (on this source's table) to the rows about $subjectId. */
    public function scope(Builder $query, string $subjectId): Builder
    {
        $this->subjectsOnly($query);

        if ($this->matcher !== null) {
            ($this->matcher)($query, $subjectId);

            return $query;
        }

        if ($this->through !== null) {
            [$table, $column] = $this->through;
            $key = $this->through[2] ?? 'id';

            return $query->whereIn($this->match, fn (Builder $sub) => $sub->from($table)->where($column, $subjectId)->select($key));
        }

        return $query->where($this->match, $subjectId);
    }

    /** Limit $query (on this source's table) to rows that are data subjects (`subjects`). */
    public function subjectsOnly(Builder $query): Builder
    {
        if ($this->subjects !== null) {
            ($this->subjects)($query);
        }

        return $query;
    }

    /** @return list<string> the top-level fields a correction may change */
    public function correctableFields(): array
    {
        return array_values(array_unique(array_map(fn (string $key) => explode('.', $key, 2)[0], array_keys($this->correctable))));
    }
}
