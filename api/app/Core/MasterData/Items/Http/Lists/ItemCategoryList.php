<?php

namespace App\Core\MasterData\Items\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\MasterData\Items\Http\Resources\ItemCategoryResource;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Item categories (MD-02): sort keys and exportable columns (EXP-01).
 * Columns read ItemCategoryResource, so field rules on `item_category`
 * apply (RBAC-05).
 */
class ItemCategoryList extends ListDefinition
{
    /** @var array<string, string>|null company names by id */
    private ?array $companyNames = null;

    public function name(): string
    {
        return 'item-categories';
    }

    public function auditAction(): string
    {
        return 'core.item_category.export';
    }

    public function title(array $filters): string
    {
        return __('core.item_category.list_title');
    }

    public function fieldRules(): ?string
    {
        return ItemCategoryResource::FIELD_RULES;
    }

    public function resource(Model $model): JsonResource
    {
        return ItemCategoryResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'name' => ListSort::column('name'),
            // By the parent's name (a category name: hidden with names); top-level categories first either way.
            'parent' => ListSort::by(['parent_id', 'name'], fn (Builder $query, string $direction) => $query->orderByRaw(
                "(select p.name from item_categories p where p.id = item_categories.parent_id) {$direction} nulls first",
            )),
            'created_at' => ListSort::column('created_at'),
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
            ListColumn::text('name', 'core.item_category.columns.name'),
            // The parent's name is a category name: hidden with names (RBAC-05).
            ListColumn::make('parent', 'core.item_category.columns.parent', ['parent_id', 'name'],
                fn (array $row, ItemCategory $category) => $category->parent?->name),
            ListColumn::make('scope', 'core.item_category.columns.scope', ['shared', 'company_id'],
                fn (array $row) => $row['shared'] ? __('core.item_category.all_companies') : ($this->companyNames()[$row['company_id']] ?? null)),
            ListColumn::text('colour', 'core.item_category.columns.colour'),
            ListColumn::archiveStatus('core.item_category.columns.status'),
            ListColumn::make('created_at', 'core.item_category.columns.created_at', ['created_at'],
                fn (array $row, ItemCategory $category, ExportValues $values) => $values->dateTime($row['created_at'], $category->company_id)),
            ListColumn::make('updated_at', 'core.item_category.columns.updated_at', ['updated_at'],
                fn (array $row, ItemCategory $category, ExportValues $values) => $values->dateTime($row['updated_at'], $category->company_id)),
        ];
    }

    /** @return array<string, string> */
    private function companyNames(): array
    {
        return $this->companyNames ??= Company::query()->pluck('name', 'id')->all();
    }

    public function exportRelations(): array
    {
        return ['parent:id,name'];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return $this->searchAndStatus($filters);
    }
}
