<?php

namespace App\Core\MasterData\Taxes\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\MasterData\Taxes\Http\Resources\TaxCategoryResource;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tax categories (MD-03): sort keys and exportable columns (EXP-01). The
 * default codes are only those of the companies the reader reaches
 * (RBAC-04), as in the JSON list. No field rules apply.
 */
class TaxCategoryList extends ListDefinition
{
    /** @var array<string, string>|null company names by id */
    private ?array $companyNames = null;

    /** @param list<string>|null $companies the companies the reader reaches (null: all) */
    public function __construct(private readonly ?array $companies) {}

    public function name(): string
    {
        return 'tax-categories';
    }

    public function auditAction(): string
    {
        return 'core.tax_category.export';
    }

    public function title(array $filters): string
    {
        return __('core.tax.categories_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return TaxCategoryResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'name' => ListSort::column('name'),
            // Ascending: shared categories first, then by company name.
            'scope' => ListSort::by(['company_id', 'shared'], fn (Builder $query, string $direction) => $query->orderByRaw(
                "(select c.name from companies c where c.id = tax_categories.company_id) {$direction} nulls first",
            )),
            'updated_at' => ListSort::column('updated_at'),
        ];
    }

    public function defaultSort(): string
    {
        return 'name';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('name', 'core.tax.category_columns.name'),
            ListColumn::make('scope', 'core.tax.category_columns.scope', ['shared', 'company_id'],
                fn (array $row) => $row['shared'] ? __('core.tax.all_companies') : ($this->companyNames()[$row['company_id']] ?? null)),
            ListColumn::make('codes', 'core.tax.category_columns.codes', ['codes'], fn (array $row, TaxCategory $category, ExportValues $values) => $values->join(array_map(
                fn (array $code) => $row['shared'] ? __('core.tax.code_in_company', ['code' => $code['code'], 'company' => $this->companyNames()[$code['company_id']] ?? '']) : $code['code'],
                $row['codes'],
            ))),
            ListColumn::archiveStatus('core.tax.category_columns.status'),
            ListColumn::make('updated_at', 'core.tax.category_columns.updated_at', ['updated_at'],
                fn (array $row, TaxCategory $category, ExportValues $values) => $values->dateTime($row['updated_at'], $category->company_id)),
        ];
    }

    /** @return array<string, string> */
    private function companyNames(): array
    {
        return $this->companyNames ??= Company::query()->pluck('name', 'id')->all();
    }

    public function exportRelations(): array
    {
        return [
            // Only the default codes of the companies the reader reaches (RBAC-04).
            'codes' => fn ($query) => $this->companies === null ? $query : $query->whereIn('company_id', $this->companies),
            'codes.taxCode:id,code',
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return $this->searchAndStatus($filters);
    }
}
