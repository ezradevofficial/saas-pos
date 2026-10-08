<?php

namespace App\Core\MasterData\Taxes\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\MasterData\Taxes\Http\Resources\PriceListResource;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A company's price lists (MD-03): sort keys and exportable columns
 * (EXP-01). No field rules apply.
 */
class PriceListList extends ListDefinition
{
    public function __construct(private readonly Company $company) {}

    public function name(): string
    {
        return 'price-lists';
    }

    public function auditAction(): string
    {
        return 'core.price_list.export';
    }

    public function title(array $filters): string
    {
        return __('core.price_list.list_title', ['company' => $this->company->name]);
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return PriceListResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'name' => ListSort::column('name'),
            'currency' => ListSort::column('currency'),
            'prices' => ListSort::column('tax_inclusive'),
            'default' => ListSort::column('is_default'),
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
            ListColumn::text('name', 'core.price_list.columns.name'),
            ListColumn::text('currency', 'core.price_list.columns.currency'),
            ListColumn::make('prices', 'core.price_list.columns.prices', ['tax_inclusive'],
                fn (array $row) => __('core.price_list.'.($row['tax_inclusive'] ? 'include_tax' : 'exclude_tax'))),
            ListColumn::make('default', 'core.price_list.columns.default', ['is_default'],
                fn (array $row) => __('core.list.'.($row['is_default'] ? 'yes' : 'no'))),
            ListColumn::archiveStatus('core.price_list.columns.status'),
            ListColumn::make('updated_at', 'core.price_list.columns.updated_at', ['updated_at'],
                fn (array $row, PriceList $list, ExportValues $values) => $values->dateTime($row['updated_at'], $list->company_id)),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return $this->searchAndStatus($filters);
    }
}
