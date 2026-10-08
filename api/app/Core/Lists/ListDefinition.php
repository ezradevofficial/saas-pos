<?php

namespace App\Core\Lists;

use App\Core\Exports\ExportValues;
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
     * Sort keys: a column name, or a closure ordering the query.
     *
     * @return array<string, string|Closure(Builder, string): void>
     */
    abstract public function sorts(): array;

    /** The sort used when the request names none (ascending). */
    abstract public function defaultSort(): string;

    /** @return list<ListColumn> exportable columns, in their default order */
    abstract public function columns(): array;

    /** @return list<string>|array<string, Closure> relations an export loads per chunk */
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

    /** Order $query by $key (`-key` descending), then by id so pages are stable. */
    public function applySort(Builder $query, ?string $sort, ?Closure $relevance = null): Builder
    {
        if ($sort === null || $sort === '') {
            if ($relevance !== null) {
                $relevance($query);
            }

            $sort = $this->defaultSort();
        }

        $descending = str_starts_with($sort, '-');
        $key = ltrim($sort, '-');
        $sorter = $this->sorts()[$key];
        $direction = $descending ? 'desc' : 'asc';

        if ($sorter instanceof Closure) {
            $sorter($query, $direction);
        } else {
            $query->orderBy($query->qualifyColumn($sorter), $direction);
        }

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
