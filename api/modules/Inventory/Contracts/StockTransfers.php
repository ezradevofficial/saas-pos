<?php

namespace Modules\Inventory\Contracts;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use Illuminate\Support\Str;
use Modules\Inventory\Documents\Transfers;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Inventory\Models\Transfer;

/**
 * A command contract for other modules (docs/modules/van-sales.md, VAN-07,
 * VAN-08, VAN-23): request, dispatch and receive an Inventory transfer
 * between two locations of one company, and read its state, so Van sales
 * never reads or writes `inventory_*` tables or calls Inventory's internals.
 * The transfer is a normal one: numbered, valued and decided by the
 * `inventory.transfer` flow (approval unless the tenant published a flow
 * without it). Permission checks are the caller's; Inventory still checks
 * that the acting user may see each item. Everything returns plain data;
 * quantities are decimal strings in each item's base unit. Every method
 * answers null / [] while Inventory is not active for the tenant (RBAC-08).
 *
 * @phpstan-type State array{id: string, number: string, status: string, from_location_id: string, to_location_id: string, lines: list<array{id: string, item_id: string, qty: string}>}
 */
final class StockTransfers
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly Transfers $transfers,
    ) {}

    public function active(): bool
    {
        return $this->modules->isActive(InventoryServiceProvider::MODULE);
    }

    /**
     * @param  list<array{item_id: string, qty: string}>  $lines  base unit, one line per item
     * @return State|null
     */
    public function request(User $by, string $fromLocationId, string $toLocationId, array $lines, ?string $note = null): ?array
    {
        if (! $this->active()) {
            return null;
        }

        $transfer = $this->transfers->create([
            'from_location_id' => $fromLocationId,
            'to_location_id' => $toLocationId,
            'note' => $note,
            'lines' => array_map(fn (array $line) => ['item_id' => $line['item_id'], 'qty' => $line['qty']], $lines),
        ], $by);

        return $this->state($transfer->id);
    }

    /** @return State|null */
    public function state(string $transferId): ?array
    {
        if (! $this->active() || ! Str::isUuid($transferId)) {
            return null;
        }

        $transfer = Transfer::query()->with('lines')->find($transferId);

        return $transfer === null ? null : [
            'id' => $transfer->id,
            'number' => (string) $transfer->number,
            'status' => $transfer->status,
            'from_location_id' => $transfer->from_location_id,
            'to_location_id' => $transfer->to_location_id,
            'lines' => $transfer->lines->map(fn ($line) => ['id' => (string) $line->id, 'item_id' => (string) $line->item_id, 'qty' => (string) $line->qty_base])->values()->all(),
        ];
    }

    /**
     * The statuses of several transfers (`pending`, `approved`, `rejected`,
     * `in_transit`, `received`, `cancelled`) by id.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function statuses(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids), fn ($id) => is_string($id) && Str::isUuid($id)));

        return ! $this->active() || $ids === [] ? [] : Transfer::query()->whereKey($ids)->pluck('status', 'id')->all();
    }

    /** Approved → in transit: the stock leaves the source. @return State|null */
    public function dispatch(User $by, string $transferId): ?array
    {
        $transfer = $this->find($transferId);

        return $transfer === null ? null : $this->state($this->transfers->dispatch($transfer, $by)->id);
    }

    /**
     * In transit → received. `received` names the quantity that arrived per
     * item (all when null); a shortfall is written off in transit with
     * `$lossReason`.
     *
     * @param  list<array{item_id: string, qty_received: string}>|null  $received
     * @return State|null
     */
    public function receive(User $by, string $transferId, ?array $received = null, ?string $lossReason = null): ?array
    {
        $transfer = $this->find($transferId);

        if ($transfer === null) {
            return null;
        }

        $lines = null;

        if ($received !== null) {
            $byItem = collect($received)->keyBy('item_id');
            $lines = $transfer->lines()->get()->map(fn ($line) => ['id' => (string) $line->id, 'qty_received' => (string) ($byItem->get($line->item_id)['qty_received'] ?? $line->qty)])->values()->all();
        }

        return $this->state($this->transfers->receive($transfer, $by, $lines, $lossReason)->id);
    }

    /** @return State|null */
    public function cancel(User $by, string $transferId, string $reason): ?array
    {
        $transfer = $this->find($transferId);

        return $transfer === null ? null : $this->state($this->transfers->cancel($transfer, $by, $reason)->id);
    }

    private function find(string $transferId): ?Transfer
    {
        return $this->active() && Str::isUuid($transferId) ? Transfer::query()->find($transferId) : null;
    }
}
