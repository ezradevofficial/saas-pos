<?php

namespace App\Core\MasterData\Items\Http\Lists;

use App\Core\CustomFields\CustomFieldLists;
use App\Core\CustomFields\Entities\ItemEntity;
use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\MasterData\Items\Http\Resources\ItemResource;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The items list (MD-02): sort keys and exportable columns (EXP-01).
 * Columns read ItemResource, so field rules on `item` apply (RBAC-05).
 */
class ItemList extends ListDefinition
{
    public function name(): string
    {
        return 'items';
    }

    public function auditAction(): string
    {
        return 'core.item.export';
    }

    public function title(array $filters): string
    {
        return __('core.item.list_title');
    }

    public function fieldRules(): string
    {
        return ItemResource::FIELD_RULES;
    }

    public function customFieldEntity(): string
    {
        return ItemEntity::KEY;
    }

    public function resource(Model $model): JsonResource
    {
        return ItemResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'code' => ListSort::column('code'),
            'name' => ListSort::column('name'),
            'type' => ListSort::column('type'),
            // By the category's name; items without one last either way.
            'category' => ListSort::by(['category_id'], fn (Builder $query, string $direction) => $query->orderByRaw(
                "(select c.name from item_categories c where c.id = items.category_id) {$direction} nulls last",
            )),
            'created_at' => ListSort::column('created_at'),
            'updated_at' => ListSort::column('updated_at'),
            // CF-03: a sort per scalar custom field, `cf_<key>`.
            ...app(CustomFieldLists::class)->sorts(ItemEntity::KEY),
        ];
    }

    public function defaultSort(): string
    {
        return 'code';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('code', 'core.item.columns.code'),
            ListColumn::text('name', 'core.item.columns.name'),
            ListColumn::make('category', 'core.item.columns.category', ['category_id'],
                fn (array $row, Item $item) => $item->category?->name),
            ListColumn::make('type', 'core.item.columns.type', ['type'],
                fn (array $row, Item $item, ExportValues $values) => $values->enum('core.item.types', $row['type'])),
            ListColumn::make('base_unit', 'core.item.columns.base_unit', ['base_uom_id'],
                fn (array $row, Item $item) => $item->baseUom === null ? null : strtoupper((string) $item->baseUom->code)),
            ListColumn::make('barcodes', 'core.item.columns.barcodes', ['barcodes'],
                fn (array $row, Item $item, ExportValues $values) => $values->join(array_column($row['barcodes'], 'barcode'))),
            ListColumn::make('tax_category', 'core.item.columns.tax_category', ['tax_category_id'],
                fn (array $row, Item $item) => $item->taxCategory?->name),
            ListColumn::make('status', 'core.item.columns.status', ['archived_at'],
                fn (array $row) => __('core.list.statuses.'.($row['archived_at'] === null ? 'active' : 'archived'))),
            ListColumn::make('created_at', 'core.item.columns.created_at', ['created_at'],
                fn (array $row, Item $item, ExportValues $values) => $values->dateTime($row['created_at'], $item->company_id)),
            ListColumn::make('updated_at', 'core.item.columns.updated_at', ['updated_at'],
                fn (array $row, Item $item, ExportValues $values) => $values->dateTime($row['updated_at'], $item->company_id)),
            // CF-03: a column per custom field, `cf_<key>`, after the built-in ones.
            ...app(CustomFieldLists::class)->columns(ItemEntity::KEY),
        ];
    }

    public function exportRelations(): array
    {
        // What ItemResource and the columns read; not images (no URL signing per row).
        return ['uoms.uom:id,code', 'barcodes', 'category:id,name', 'baseUom:id,code', 'taxCategory:id,name'];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = [];

        if (($filters['search'] ?? '') !== '') {
            $summary[__('core.list.search')] = $filters['search'];
        }

        if (isset($filters['category'])) {
            $summary[__('core.item.columns.category')] = (string) ItemCategory::query()->whereKey($filters['category'])->value('name');
        }

        if (isset($filters['type'])) {
            $summary[__('core.item.columns.type')] = $values->enum('core.item.types', $filters['type']);
        }

        if (isset($filters['barcode'])) {
            $summary[__('core.item.columns.barcode')] = $filters['barcode'];
        }

        $summary = [...$summary, ...app(CustomFieldLists::class)->summary(ItemEntity::KEY, $filters['custom'] ?? null)];
        $summary[__('core.list.status')] = __('core.list.statuses.'.($filters['status'] ?? 'active'));

        return $summary;
    }
}
