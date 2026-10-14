<?php

namespace Modules\Loyalty\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Credit\Ageing;
use Modules\Loyalty\Http\Resources\AccountResource;

/**
 * LOY-14, LOY-18, EXP-01: credit accounts with their balance and limit;
 * as the ageing report (`loyalty-ageing`), with the open amount per
 * bucket at the as-of date (set by the controller on each row).
 */
class AccountList extends ListDefinition
{
    public const RELATIONS = ['company', 'party'];

    public function __construct(private readonly bool $ageing = false) {}

    public function name(): string
    {
        return $this->ageing ? 'loyalty-ageing' : 'loyalty-accounts';
    }

    public function auditAction(): string
    {
        return $this->ageing ? 'loyalty.ageing.export' : 'loyalty.credit_account.export';
    }

    public function title(array $filters): string
    {
        return $this->ageing ? __('loyalty.ageing.title') : __('loyalty.credit.list_title');
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
        return [
            'balance' => ListSort::column('balance'),
            'opened_at' => ListSort::column('opened_at'),
            'status' => ListSort::column('status'),
        ];
    }

    public function defaultSort(): string
    {
        return '-balance';
    }

    public function columns(): array
    {
        $columns = [
            ListColumn::make('customer', 'loyalty.credit.columns.customer', ['party'], fn (array $row) => $row['party']['name'] ?? null),
            ListColumn::make('company', 'loyalty.credit.columns.company', ['company'], fn (array $row) => $row['company']['name'] ?? null),
            ListColumn::make('balance', 'loyalty.credit.columns.balance', ['balance'], fn (array $row, Model $m, ExportValues $values) => $values->money($row['balance'])),
            ListColumn::make('limit', 'loyalty.credit.columns.limit', ['credit_limit'], fn (array $row, Model $m, ExportValues $values) => $values->money($row['credit_limit'])),
            ListColumn::make('status', 'loyalty.credit.columns.status', ['status'], fn (array $row) => __('loyalty.credit.statuses.'.$row['status'])),
        ];

        if ($this->ageing) {
            foreach (Ageing::BUCKETS as $bucket) {
                $columns[] = ListColumn::make('ageing_'.$bucket, 'loyalty.ageing.buckets.'.$bucket, ['ageing'],
                    fn (array $row, Model $m, ExportValues $values) => $values->money(['amount_minor' => $row['ageing']['buckets'][$bucket] ?? '0', 'currency' => $row['currency']]));
            }
        }

        return $columns;
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return $this->searchAndStatus($filters, archivable: false);
    }
}
