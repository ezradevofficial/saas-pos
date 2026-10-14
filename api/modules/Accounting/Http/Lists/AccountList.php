<?php

namespace Modules\Accounting\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Accounting\Http\Resources\AccountResource;

/** ACC-01, EXP-01: a company's chart of accounts, by code. */
class AccountList extends ListDefinition
{
    public function name(): string
    {
        return 'accounting-accounts';
    }

    public function auditAction(): string
    {
        return 'accounting.account.export';
    }

    public function title(array $filters): string
    {
        return __('accounting.lists.accounts');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return AccountResource::make($model);
    }

    public function sorts(): array
    {
        return ['code' => ListSort::column('code'), 'name' => ListSort::column('name'), 'type' => ListSort::column('type')];
    }

    public function defaultSort(): string
    {
        return 'code';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('code', 'accounting.columns.code'),
            ListColumn::text('name', 'accounting.columns.name'),
            ListColumn::make('type', 'accounting.columns.type', ['type'], fn (array $row) => __('accounting.account_types.'.$row['type'])),
            ListColumn::make('subtype', 'accounting.columns.subtype', ['subtype'], fn (array $row) => __('accounting.subtypes.'.$row['subtype'])),
            ListColumn::make('postable', 'accounting.columns.postable', ['postable'], fn (array $row) => __($row['postable'] ? 'accounting.yes' : 'accounting.no')),
            ListColumn::text('currency', 'accounting.columns.currency'),
            ListColumn::archiveStatus('accounting.columns.status'),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return $this->searchAndStatus($filters);
    }
}
