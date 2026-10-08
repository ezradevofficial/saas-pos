<?php

namespace App\Core\MasterData\PaymentMethods\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\MasterData\PaymentMethods\Http\Resources\PaymentMethodResource;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A company's payment methods (MD-04): sort keys and exportable columns
 * (EXP-01). Provider settings and credentials are never exported, not
 * even their names: only the columns below exist. Columns read
 * PaymentMethodResource, so field rules on `payment_method` apply
 * (RBAC-05); "At the till" depends on settings and credentials being
 * complete, so it is dropped when either is hidden.
 */
class PaymentMethodList extends ListDefinition
{
    public function __construct(private readonly Company $company) {}

    public function name(): string
    {
        return 'payment-methods';
    }

    public function auditAction(): string
    {
        return 'core.payment_method.export';
    }

    public function title(array $filters): string
    {
        return __('core.payment_method.list_title', ['company' => $this->company->name]);
    }

    public function fieldRules(): ?string
    {
        return PaymentMethodResource::FIELD_RULES;
    }

    public function fieldSources(): array
    {
        return PaymentMethodResource::SOURCES;
    }

    public function resource(Model $model): JsonResource
    {
        return PaymentMethodResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'position' => ListSort::column('position'),
            'name' => ListSort::column('name'),
            'type' => ListSort::column('type'),
            'provider' => ListSort::by(['provider'], fn (Builder $query, string $direction) => $query->orderByRaw("{$query->qualifyColumn('provider')} {$direction} nulls last")),
            'currency' => ListSort::by(['currency'], fn (Builder $query, string $direction) => $query->orderByRaw("{$query->qualifyColumn('currency')} {$direction} nulls last")),
            // Ascending: switched-on methods first.
            'till' => ListSort::by(['active'], fn (Builder $query, string $direction) => $query->orderBy($query->qualifyColumn('active'), $direction === 'asc' ? 'desc' : 'asc')),
            'updated_at' => ListSort::column('updated_at'),
        ];
    }

    public function defaultSort(): string
    {
        // Till order, as before sorting existed.
        return 'position';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('position', 'core.payment_method.columns.position', ['position'],
                fn (array $row, PaymentMethod $method, ExportValues $values) => $values->integer($row['position'])),
            ListColumn::text('name', 'core.payment_method.columns.name'),
            ListColumn::make('type', 'core.payment_method.columns.type', ['type'],
                fn (array $row, PaymentMethod $method, ExportValues $values) => $values->enum('core.payment_method.types', $row['type'])),
            ListColumn::make('provider', 'core.payment_method.columns.provider', ['provider'],
                fn (array $row, PaymentMethod $method, ExportValues $values) => $values->enum('core.payment_method.providers', $row['provider'])),
            ListColumn::text('currency', 'core.payment_method.columns.currency'),
            // As the web app shows it: a provider method not yet configured needs setup.
            ListColumn::make('till', 'core.payment_method.columns.till', ['active', 'configured', 'type'],
                fn (array $row) => __('core.payment_method.till_statuses.'.match (true) {
                    (bool) $row['active'] => 'on',
                    ! $row['configured'] && in_array($row['type'], PaymentMethod::PROVIDER_TYPES, true) => 'setup',
                    default => 'off',
                })),
            ListColumn::archiveStatus('core.payment_method.columns.status'),
            ListColumn::make('updated_at', 'core.payment_method.columns.updated_at', ['updated_at'],
                fn (array $row, PaymentMethod $method, ExportValues $values) => $values->dateTime($row['updated_at'], $method->company_id)),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return $this->searchAndStatus($filters);
    }
}
