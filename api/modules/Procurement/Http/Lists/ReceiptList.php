<?php

namespace Modules\Procurement\Http\Lists;

use App\Core\Lists\ListColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Http\Resources\ReceiptResource;

/** PRC-14, PRC-21: the receipts list and its export, newest first. */
class ReceiptList extends ProcurementList
{
    public const RELATIONS = ['company', 'branch', 'location', 'party', 'order', 'creator'];

    public function name(): string
    {
        return 'procurement-receipts';
    }

    public function auditAction(): string
    {
        return 'procurement.receipt.export';
    }

    public function title(array $filters): string
    {
        return __('procurement.lists.receipts');
    }

    public function resource(Model $model): JsonResource
    {
        return ReceiptResource::make($model);
    }

    public function columns(): array
    {
        return [
            self::text('number'),
            self::at('created_at'),
            self::status(),
            self::named('party'),
            self::named('location'),
            ListColumn::make('order', 'procurement.columns.order', ['order'], fn (array $row) => $row['order']['number'] ?? null),
            self::at('received_at'),
            self::money('base_value'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
