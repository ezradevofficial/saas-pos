<?php

namespace App\Core\Lists\Http;

use App\Core\Exports\ListExport;
use App\Core\Lists\ListDefinition;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * The list contract on a FormRequest, without the status filter (lists and
 * pickers plan, API contract): `?sort=key|-key` (whitelisted by the list;
 * unknown keys are refused, 422), a plain text `?search=` helper, and an
 * export: `?format=csv|xlsx|pdf` with `?columns[]=key...` (whitelisted, in
 * the order given; default all). An export needs the same authorisation as
 * the list (no separate permission yet). Lists of archivable records use
 * ListsRecords, which adds `?status` and `?per_page`.
 */
trait SortsAndExports
{
    abstract public function list(): ListDefinition;

    /** @return array<string, list<mixed>> */
    protected function sortAndExportRules(): array
    {
        $list = $this->list();

        return [
            'sort' => ['sometimes', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail) use ($list) {
                if (! is_string($value) || preg_match('/^-?([a-z0-9_]+)$/', $value, $match) !== 1 || ! array_key_exists($match[1], $list->sorts())) {
                    $fail(__('core.list.sort_unknown', ['sort' => $value]));
                } elseif ($list->hides($list->sorts()[$match[1]]->fields, $list->hiddenFields($this))) {
                    // RBAC-05: the order alone would reveal the hidden values' ranking.
                    $fail(__('core.list.sort_hidden'));
                }
            }],
            'format' => ['sometimes', 'string', 'in:'.implode(',', ListExport::FORMATS)],
            'columns' => ['sometimes', 'array', 'min:1'],
            'columns.*' => ['required', 'string', 'distinct', function (string $attribute, mixed $value, Closure $fail) use ($list) {
                if (! in_array($value, $list->columnKeys(), true)) {
                    $fail(__('core.list.column_unknown', ['column' => $value]));
                }
            }],
        ];
    }

    /**
     * A rule refusing a filter on a field the user's field rules hide
     * (RBAC-05): the matching rows alone would reveal the hidden values.
     */
    protected function visibleFilter(string ...$fields): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($fields) {
            $list = $this->list();

            if ($list->hides($fields, $list->hiddenFields($this))) {
                $fail(__('core.list.filter_hidden'));
            }
        };
    }

    public function sort(): ?string
    {
        return $this->validated('sort');
    }

    /** Sort the query as asked, else by relevance (a search) and the list's default; ties by id. */
    public function applySort(Builder $query, ?Closure $relevance = null): Builder
    {
        $list = $this->list();

        return $list->applySort($query, $this->sort(), $relevance, $list->hiddenFields($this));
    }

    /**
     * `?search=`: rows where any of $columns contains the text (case and
     * accent of the column as stored; LIKE wildcards are literal). A column
     * whose field the user's field rules hide is skipped (RBAC-05).
     *
     * @param  array<string, string>  $columns  field => column of the query's table (or a qualified column)
     */
    public function applySearch(Builder $query, array $columns): Builder
    {
        $search = trim((string) $this->validated('search', ''));

        if ($search === '') {
            return $query;
        }

        $like = '%'.addcslashes($search, '\\%_').'%';

        return $query->where(function (Builder $q) use ($columns, $like) {
            $q->whereRaw('false');

            foreach ($columns as $field => $column) {
                if (! $this->hidesField($field)) {
                    $column = str_contains($column, '.') ? $column : $q->qualifyColumn($column);
                    $q->orWhereRaw("{$column}::text ilike ?", [$like]);
                }
            }
        });
    }

    /** True when the user's field rules hide $field of this list (RBAC-05), e.g. to skip it in a search. */
    public function hidesField(string $field): bool
    {
        $list = $this->list();

        return $list->hides([$field], $list->hiddenFields($this));
    }

    /**
     * True when the request pages (`?page` or `?per_page`). Lists that
     * answered every row before paging existed keep doing so without them.
     */
    public function wantsPage(): bool
    {
        return $this->query('page') !== null || $this->query('per_page') !== null;
    }

    public function wantsExport(): bool
    {
        return $this->exportFormat() !== null;
    }

    public function exportFormat(): ?string
    {
        return $this->validated('format');
    }

    /** @return list<string> the columns asked for, in order (all by default) */
    public function exportColumns(): array
    {
        return array_values($this->validated('columns', $this->list()->columnKeys()));
    }

    /**
     * The validated filters, search and sort (for the audit entry and the
     * PDF's filter summary).
     *
     * @return array<string, mixed>
     */
    public function listFilters(): array
    {
        return Arr::except($this->validated(), ['format', 'columns', 'per_page', 'page']);
    }
}
