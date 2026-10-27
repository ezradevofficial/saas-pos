<?php

namespace Modules\VanSales\Reconciliation;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use Modules\Inventory\Contracts\StockTransfers;
use Modules\VanSales\Models\Count;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Support\Errors;

/**
 * VAN-23: the unload of a counted van, an Inventory transfer from the van to
 * the warehouse of the day's load. The storekeeper receives it with the
 * counts at the warehouse: dispatch (when the transfer is approved) and
 * receipt in one step; a difference is Inventory's loss in transit.
 */
class Unloads
{
    public function __construct(
        private readonly StockTransfers $transfers,
        private readonly Auditor $auditor,
        private readonly Counts $counts,
    ) {}

    /** @return array{status: string, number: string}|null null when the count has no unload */
    public function of(Count $count): ?array
    {
        if ($count->unload_transfer_id === null) {
            return null;
        }

        $state = $this->transfers->state($count->unload_transfer_id);

        if ($state === null) {
            return null;
        }

        return ['status' => $this->status($state['status']), 'number' => $state['number']];
    }

    public function status(string $transferStatus): string
    {
        return match ($transferStatus) {
            'pending' => 'awaiting_approval',
            'approved' => 'approved',
            'in_transit' => 'released',
            'received' => 'received',
            'rejected' => 'rejected',
            'cancelled' => 'cancelled',
            default => $transferStatus,
        };
    }

    /** @param list<array{item_id: string, qty_received: string}>|null $lines */
    public function receive(Count $count, User $by, ?array $lines): array
    {
        return Errors::transaction(function () use ($count, $by, $lines) {
            $state = $count->unload_transfer_id === null ? null : $this->transfers->state($count->unload_transfer_id);

            if ($state === null || ! in_array($state['status'], ['approved', 'in_transit'], true)) {
                throw Errors::make(409, 'unload_not_ready');
            }

            if ($state['status'] === 'approved') {
                $this->transfers->dispatch($by, $state['id']);
            }

            $known = array_column($state['lines'], 'item_id');

            foreach ($lines ?? [] as $index => $line) {
                if (! in_array($line['item_id'], $known, true)) {
                    throw Errors::make(422, 'item_unknown', field: "lines.{$index}.item_id");
                }
            }

            $done = $this->transfers->receive($by, $state['id'], $lines, __('van_sales.loads.unload_short_reason'));
            // Whatever the warehouse found short goes to an approver; the run closes once nothing waits (VAN-23).
            $this->counts->unloadShortfall($count, $done['lines'] ?? [], $by);
            $run = Run::query()->whereKey($count->run_id)->lockForUpdate()->first();
            $run === null || $this->counts->closeIfDone($run);
            $this->auditor->record('van_sales.count.unload_receive', $count, null, ['transfer' => $done['number'] ?? null, 'lines' => count($lines ?? $state['lines'])], ['user_id' => $by->id]);

            return ['status' => $this->status($done['status'] ?? 'received'), 'number' => (string) ($done['number'] ?? '')];
        });
    }
}
