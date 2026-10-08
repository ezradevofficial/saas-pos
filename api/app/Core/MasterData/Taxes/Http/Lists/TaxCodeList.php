<?php

namespace App\Core\MasterData\Taxes\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\MasterData\Taxes\Http\Resources\TaxCodeResource;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A company's tax codes (MD-03, CP-02): sort keys and exportable columns
 * (EXP-01), with the rate in force today as the list shows it. No field
 * rules apply.
 */
class TaxCodeList extends ListDefinition
{
    public function __construct(private readonly Company $company) {}

    public function name(): string
    {
        return 'tax-codes';
    }

    public function auditAction(): string
    {
        return 'core.tax_code.export';
    }

    public function title(array $filters): string
    {
        return __('core.tax.list_title', ['company' => $this->company->name]);
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return TaxCodeResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'code' => ListSort::column('code'),
            'name' => ListSort::column('name'),
            'kind' => ListSort::column('kind'),
            'fiscal_code' => ListSort::column('fiscal_code'),
            'updated_at' => ListSort::column('updated_at'),
        ];
    }

    public function defaultSort(): string
    {
        return 'code';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('code', 'core.tax.columns.code'),
            ListColumn::text('name', 'core.tax.columns.name'),
            ListColumn::make('kind', 'core.tax.columns.kind', ['kind'],
                fn (array $row, TaxCode $code, ExportValues $values) => $values->enum('core.tax.kinds', $row['kind'])),
            ListColumn::make('rate', 'core.tax.columns.rate', ['current_rate', 'rate_needed', 'kind'], fn (array $row, TaxCode $code, ExportValues $values) => match (true) {
                $row['kind'] === 'exempt' => __('core.tax.exempt'),
                $row['rate_needed'] => __('core.tax.rate_needed'),
                default => $values->decimal((string) $row['current_rate']['rate']).'%',
            }),
            ListColumn::make('since', 'core.tax.columns.since', ['current_rate'],
                fn (array $row, TaxCode $code, ExportValues $values) => $values->date($row['current_rate']['effective_from'] ?? null)),
            ListColumn::text('fiscal_code', 'core.tax.columns.fiscal_code'),
            ListColumn::archiveStatus('core.tax.columns.status'),
            ListColumn::make('updated_at', 'core.tax.columns.updated_at', ['updated_at'],
                fn (array $row, TaxCode $code, ExportValues $values) => $values->dateTime($row['updated_at'], $code->company_id)),
        ];
    }

    public function exportRelations(): array
    {
        return ['rates', 'company:id,timezone'];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return $this->searchAndStatus($filters);
    }
}
