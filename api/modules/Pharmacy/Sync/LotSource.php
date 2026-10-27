<?php

namespace Modules\Pharmacy\Sync;

use App\Core\Sync\Contracts\IncrementalSource;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\Sources\Iso;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\PharmacyServiceProvider;
use Modules\Pharmacy\Support\Qty;

/**
 * PHA-25, PHA-07, PHA-08, NFR-04: the lots with stock at the till's
 * location (`pharmacy_lots`, incremental, read-only), one row per balance:
 * `{id, item_id, lot_id, batch_number, manufactured_on, expires_on, qty,
 * blocked, updated_at}`. The till picks lots first-expiry-first-out from
 * them, refuses expired and blocked ones and warns near expiry, offline.
 * `blocked` is `quarantined` or `recalled` (null when sellable). A lot
 * quarantined or recalled re-stamps its balances, so tills learn it on the
 * next pull; a balance at zero leaves the till (a tombstone) and returns
 * when stock arrives. Server-wins: the till never writes these.
 */
class LotSource implements IncrementalSource
{
    public function key(): string
    {
        return 'pharmacy_lots';
    }

    public function module(): string
    {
        return PharmacyServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function table(): string
    {
        return 'pharmacy_lot_balances';
    }

    public function visible(Builder $query, DeviceScope $scope): Builder
    {
        return $query->where('pharmacy_lot_balances.location_id', $scope->location->id);
    }

    public function rows(array $ids, DeviceScope $scope): array
    {
        return $this->visible(DB::connection(TenantContext::CONNECTION)->table('pharmacy_lot_balances'), $scope)
            ->join('pharmacy_lots', 'pharmacy_lots.id', '=', 'pharmacy_lot_balances.lot_id')
            ->whereIn('pharmacy_lot_balances.id', $ids)
            ->where('pharmacy_lot_balances.qty', '>', 0)
            ->get([
                'pharmacy_lot_balances.id', 'pharmacy_lot_balances.item_id', 'pharmacy_lot_balances.lot_id', 'pharmacy_lot_balances.qty', 'pharmacy_lot_balances.updated_at',
                'pharmacy_lots.batch_number', 'pharmacy_lots.manufactured_on', 'pharmacy_lots.expires_on', 'pharmacy_lots.status', 'pharmacy_lots.recall_id',
            ])
            ->mapWithKeys(fn (object $row) => [$row->id => [
                'id' => $row->id,
                'item_id' => $row->item_id,
                'lot_id' => $row->lot_id,
                'batch_number' => $row->batch_number,
                'manufactured_on' => $row->manufactured_on,
                'expires_on' => $row->expires_on,
                'qty' => Qty::show((string) $row->qty),
                'blocked' => $row->recall_id !== null ? 'recalled' : ($row->status === Lot::QUARANTINED ? 'quarantined' : null),
                'updated_at' => Iso::of($row->updated_at),
            ]])
            ->all();
    }
}
