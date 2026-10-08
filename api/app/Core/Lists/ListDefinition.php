<?php

namespace App\Core\Lists;

use App\Core\Exports\ExportValues;
use App\Core\MasterData\Items\Http\Resources\HidesFields;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a list endpoint sorts by and exports (lists and pickers plan, API
 * contract; EXP-01). One subclass per list; its FormRequest returns it
 * from `list()` (see ListsRecords) and the controller hands the filtered
 * query to ListExport.
 */
abstract class ListDefinition
{
    /** File name stem: `<name>-YYYY-MM-DD.<ext>`. */
    abstract public function name(): string;

    /** Audit action of an export (AUD-01), `<module>.<resource>.export`. */
    abstract public function auditAction(): string;

    /** Translated title printed on a PDF. */
    abstract public function title(array $filters): string;

    /** The field rules resource the API resource applies (RBAC-05). */
    abstract public function fieldRules(): string;

    /**
     * Output keys built from several columns: hidden when any of them is
     * (as the resource does).
     *
     * @return array<string, list<string>>
     */
    public function fieldSources(): array
    {
        return [];
    }

    /** The API resource the JSON list uses, for one record. */
    abstract public function resource(Model $model): JsonResource;

    /**
     * Sort keys, each with the resource fields it orders by.
     *
     * @return array<string, ListSort>
     */
    abstract public function sorts(): array;

    /** The sort used when the request names none (ascending). */
    abstract public function defaultSort(): string;

    /** @return list<ListColumn> exportable columns, in their default order */
    abstract public function columns(): array;

    /**
     * The relations an export loads per chunk, in place of the JSON
     * list's (an export skips what its columns never read, such as images
     * and their signed URLs).
     *
     * @return list<string>|array<string, Closure>
     */
    public function exportRelations(): array
    {
        return [];
    }

    /**
     * The filters as printed on a PDF: translated label => display value.
     *
     * @param  array<string, mixed>  $filters  the validated filters (search, sort, status, ...)
     * @return array<string, string>
     */
    public function filterSummary(array $filters, ExportValues $values): array
    {
        return [];
    }

    /** @return list<string> */
    public function columnKeys(): array
    {
        return array_map(fn (ListColumn $column) => $column->key, $this->columns());
    }

    /**
     * The fields of this list hidden from the request's user (RBAC-05),
     * cached on the request as the resource caches them.
     *
     * @return list<string>
     */
    public function hiddenFields(Request $request): array
    {
        return HidesFields::hidden($request, $this->fieldRules());
    }

    /**
     * True when any of $fields is hidden, directly or through a column it
     * is built from (fieldSources()).
     *
     * @param  list<string>  $fields
     * @param  list<string>  $hidden
     */
    public function hides(array $fields, array $hidden): bool
    {
        $sources = $this->fieldSources();

        foreach ($fields as $field) {
            if (in_array($field, $hidden, true) || array_intersect($sources[$field] ?? [], $hidden) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Order $query by $sort (`-key` descending), then by id so pages are
     * stable. Without a sort: by $relevance (a search) when given, then by
     * the default sort, unless the user can't see its fields (the order
     * would reveal their ranking; RBAC-05), then by id alone.
     *
     * @param  list<string>  $hidden  the fields hidden from the user
     */
    public function applySort(Builder $query, ?string $sort, ?Closure $relevance = null, array $hidden = []): Builder
    {
        if ($sort === null || $sort === '') {
            if ($relevance !== null) {
                $relevance($query);
            }

            $sort = $this->defaultSort();

            if ($this->hides($this->sorts()[$sort]->fields, $hidden)) {
                return $query->orderBy($query->qualifyColumn('id'));
            }
        }

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $this->sorts()[ltrim($sort, '-')]->apply($query, $direction);

        return $query->orderBy($query->qualifyColumn('id'), $direction);
    }

    /**
     * The record as the JSON list renders it for the request's user, with
     * the fields their field rules hide left out (RBAC-05).
     *
     * @return array<string, mixed>
     */
    public function resolve(Model $model, Request $request): array
    {
        return $this->resource($model)->resolve($request);
    }
}
