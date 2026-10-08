<?php

namespace App\Core\Currency\Http\Lists;

use App\Core\Currency\Http\Resources\TenantCurrencyResource;
use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The tenant's currencies (CUR-01): sort keys and exportable columns
 * (EXP-01). No field rules apply.
 */
class TenantCurrencyList extends ListDefinition
{
    public function name(): string
    {
        return 'currencies';
    }

    public function auditAction(): string
    {
        return 'core.currency.export';
    }

    public function title(array $filters): string
    {
        return __('core.currency.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return TenantCurrencyResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'code' => ListSort::column('code'),
            'decimals' => ListSort::column('decimals'),
            'cash_rounding' => ListSort::column('cash_rounding_minor'),
            // Ascending: switched-on currencies first.
            'status' => ListSort::by(['active'], fn ($query, string $direction) => $query->orderBy($query->qualifyColumn('active'), $direction === 'asc' ? 'desc' : 'asc')),
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
            ListColumn::text('code', 'core.currency.columns.code'),
            ListColumn::text('name', 'core.currency.columns.name'),
            ListColumn::make('decimals', 'core.currency.columns.decimals', ['decimals'], fn (array $row) => (string) $row['decimals']),
            ListColumn::make('cash_rounding', 'core.currency.columns.cash_rounding', ['cash_rounding_minor', 'code'],
                fn (array $row, Model $currency, ExportValues $values) => $row['cash_rounding_minor'] === null ? null
                    : $row['code'].' '.$values->minor((string) $row['cash_rounding_minor'], (int) $row['decimals'])),
            ListColumn::make('status', 'core.currency.columns.status', ['active'],
                fn (array $row) => __('core.currency.statuses.'.($row['active'] ? 'active' : 'inactive'))),
            ListColumn::make('updated_at', 'core.currency.columns.updated_at', ['updated_at'],
                fn (array $row, Model $currency, ExportValues $values) => $values->dateTime($row['updated_at'])),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return $this->searchAndStatus($filters, archivable: false);
    }
}
