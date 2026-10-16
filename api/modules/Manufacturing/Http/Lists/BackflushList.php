<?php

namespace Modules\Manufacturing\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Http\Resources\BackflushResource;

/** MFG-07, MFG-08: what POS sales consumed through recipes, and what voids and refunds gave back. */
class BackflushList extends ManufacturingList
{
    public const RELATIONS = ['location', 'lines.item', 'lines.soldItem'];

    public function name(): string
    {
        return 'manufacturing-backflushes';
    }

    public function auditAction(): string
    {
        return 'manufacturing.backflush.export';
    }

    public function title(array $filters): string
    {
        return __('manufacturing.lists.backflushes');
    }

    public function resource(Model $model): JsonResource
    {
        return BackflushResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'occurred_at' => ListSort::column('occurred_at'),
            'reference' => ListSort::column('reference'),
        ];
    }

    public function defaultSort(): string
    {
        return '-occurred_at';
    }

    public function columns(): array
    {
        return [
            self::at('occurred_at'),
            ListColumn::text('reference', 'manufacturing.columns.reference'),
            self::enum('kind', 'manufacturing.backflush_kinds'),
            self::named('location', 'location'),
            ListColumn::make('menu_items', 'manufacturing.columns.menu_items', ['menu_items'], fn (array $row) => implode(', ', array_column($row['menu_items'] ?? [], 'name'))),
            ListColumn::make('ingredients', 'manufacturing.columns.ingredients', ['lines'], fn (array $row) => (string) count($row['lines'] ?? [])),
            self::money('value'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
