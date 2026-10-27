<?php

namespace Modules\Pharmacy\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListSort;
use App\Core\MasterData\Items\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Http\Resources\StockResource;
use Modules\Pharmacy\Models\Lot;

/** PHA-05, PHA-24: stock by lot and location, earliest expiry first. */
class LotList extends PharmacyList
{
    public const RELATIONS = ['lot', 'item', 'location'];

    public function name(): string
    {
        return 'pharmacy-lots';
    }

    public function auditAction(): string
    {
        return 'pharmacy.lot.export';
    }

    public function title(array $filters): string
    {
        return __('pharmacy.lists.lots');
    }

    public function resource(Model $model): JsonResource
    {
        return StockResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'expires_on' => ListSort::by(['expires_on'], fn ($query, string $direction) => $query->orderBy(
                Lot::query()->select('expires_on')->whereColumn('pharmacy_lots.id', 'pharmacy_lot_balances.lot_id'), $direction)),
            'qty' => ListSort::column('qty'),
            'item' => ListSort::by(['item'], fn ($query, string $direction) => $query->orderBy(
                Item::query()->select('name')->whereColumn('items.id', 'pharmacy_lot_balances.item_id'), $direction)),
        ];
    }

    public function defaultSort(): string
    {
        return 'expires_on';
    }

    public function columns(): array
    {
        return [
            self::item(),
            self::col('batch_number', ['batch_number'], fn (array $row) => $row['batch_number']),
            self::on('manufactured_on'),
            self::on('expires_on'),
            self::col('days_left', ['days_left'], fn (array $row, $model, ExportValues $values) => $values->integer($row['days_left'])),
            self::col('state', ['state'], fn (array $row) => __('pharmacy.states.'.$row['state'])),
            self::qty('qty', 'qty'),
            self::named('location', 'location'),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
