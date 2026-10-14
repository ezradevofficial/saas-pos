<?php

namespace Modules\Loyalty\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Http\Resources\LoyaltySaleResource;
use Modules\Loyalty\Models\LoyaltySale;

/**
 * LOY-12, H2: POS sales as Loyalty saw them, newest first; the review list
 * shows those with flags (overdrawn balances, coupons used too often,
 * promotions that differ, credit sold without the permission, gift card
 * sales that do not match).
 */
class SaleList extends ListDefinition
{
    public const RELATIONS = ['party'];

    public function name(): string
    {
        return 'loyalty-sales';
    }

    public function auditAction(): string
    {
        return 'loyalty.sale.export';
    }

    public function title(array $filters): string
    {
        return __('loyalty.sales.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return LoyaltySaleResource::make($model);
    }

    public function sorts(): array
    {
        return ['sold_at' => ListSort::column('sold_at'), 'status' => ListSort::column('status')];
    }

    public function defaultSort(): string
    {
        return '-sold_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('receipt', 'loyalty.sales.columns.receipt', ['receipt_number'], fn (array $row) => $row['receipt_number']),
            ListColumn::make('sold_at', 'loyalty.sales.columns.sold_at', ['sold_at'],
                fn (array $row, LoyaltySale $sale, ExportValues $values) => $values->dateTime($row['sold_at'], $sale->company_id)),
            ListColumn::make('customer', 'loyalty.sales.columns.customer', ['party'], fn (array $row) => $row['party']['name'] ?? null),
            ListColumn::make('total', 'loyalty.sales.columns.total', ['total'], fn (array $row, Model $m, ExportValues $values) => $values->money($row['total'])),
            ListColumn::make('flags', 'loyalty.sales.columns.flags', ['flags'], fn (array $row) => implode(', ', array_map(fn (array $flag) => __('loyalty.flags.'.$flag['code']), $row['flags']))),
        ];
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
