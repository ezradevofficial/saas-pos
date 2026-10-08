<?php

namespace App\Core\Lists\Http;

use App\Core\Exports\ListExport;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\Http\Requests\ListsArchivable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * The list contract on a FormRequest (lists and pickers plan, API
 * contract): ListsArchivable's `?status` and `?per_page`, plus
 * `?sort=key|-key` (whitelisted by the list; unknown keys are refused,
 * 422), and an export: `?format=csv|xlsx|pdf` with `?columns[]=key...`
 * (whitelisted, in the order given; default all). An export needs the
 * same authorisation as the list (no separate permission yet).
 */
trait ListsRecords
{
    use ListsArchivable {
        listRules as archivableRules;
    }

    abstract public function list(): ListDefinition;

    /** @return array<string, list<mixed>> */
    protected function listRules(): array
    {
        $list = $this->list();

        return [
            ...$this->archivableRules(),
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

    /** True when the user's field rules hide $field of this list (RBAC-05), e.g. to skip it in a search. */
    public function hidesField(string $field): bool
    {
        $list = $this->list();

        return $list->hides([$field], $list->hiddenFields($this));
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
