<?php

namespace Modules\VanSales\Loads;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Modules\Inventory\Contracts\StockTransfers;
use Modules\VanSales\Models\Load;
use Modules\VanSales\Models\LoadLine;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\Van;
use Modules\VanSales\Support\Errors;
use Modules\VanSales\Support\Places;
use Modules\VanSales\Support\Qty;

/**
 * VAN-07, VAN-08: load sheets. A sheet is an Inventory transfer from a
 * warehouse to the van's location (through Inventory's contract, never its
 * tables) with the rep's confirmation on top. Without Inventory the sheet is
 * a plain list the rep confirms (VAN-01).
 *
 * Status shown: `awaiting_approval`, `approved`, `rejected` (the transfer's
 * flow), `released` (dispatched, or no transfer), `confirmed`, `cancelled`.
 */
class Loads
{
    public function __construct(
        private readonly StockTransfers $transfers,
        private readonly Places $places,
    ) {}

    /** @param array{van_id: string, from_location_id: string, note?: ?string, run_id?: ?string, lines: list<array{item_id: string, qty: string}>} $data */
    public function request(array $data, User $by): Load
    {
        $van = Van::query()->whereNull('archived_at')->find($data['van_id']) ?? throw Errors::make(422, 'van_unknown', field: 'van_id');
        $from = $this->places->open($data['from_location_id'], 'from_location_id');

        if ($data['from_location_id'] === $van->location_id) {
            throw Errors::make(422, 'load_same_location', field: 'from_location_id');
        }

        if ($from['company_id'] !== $van->company_id) {
            throw Errors::make(422, 'load_cross_company', field: 'from_location_id');
        }

        $lines = [];

        foreach ($data['lines'] as $index => $line) {
            $qty = Qty::of((string) $line['qty']);
            $item = Item::query()->whereKey($line['item_id'])->where('type', 'stock')->whereNull('archived_at')->first();

            if ($item === null) {
                throw Errors::make(422, 'item_unknown', field: "lines.{$index}.item_id");
            }

            $lines[$item->id] = ($lines[$item->id] ?? Qty::zero())->plus($qty);
        }

        $runId = $data['run_id'] ?? null;

        if ($runId !== null && ! Run::query()->where('van_id', $van->id)->whereKey($runId)->exists()) {
            throw Errors::make(422, 'run_unknown', field: 'run_id');
        }

        return Errors::transaction(function () use ($data, $by, $van, $lines, $runId) {
            $transfer = $this->transfers->request($by, $data['from_location_id'], $van->location_id,
                array_map(fn (string $itemId, $qty) => ['item_id' => $itemId, 'qty' => (string) Qty::show($qty)], array_keys($lines), array_values($lines)), $data['note'] ?? null);

            $load = Load::create([
                'company_id' => $van->company_id,
                'branch_id' => $van->branch_id,
                'van_id' => $van->id,
                'run_id' => $runId,
                'from_location_id' => $data['from_location_id'],
                'to_location_id' => $van->location_id,
                'transfer_id' => $transfer['id'] ?? null,
                'number' => $transfer['number'] ?? null,
                'state' => 'open',
                'note' => $data['note'] ?? null,
                'requested_by' => $by->id,
                'released_at' => $transfer === null ? CarbonImmutable::now() : null,
            ]);

            foreach ($lines as $itemId => $qty) {
                LoadLine::create(['load_id' => $load->id, 'item_id' => $itemId, 'qty' => (string) $qty]);
            }

            return $load->refresh();
        });
    }

    /** The sheet's status as people see it. */
    public function status(Load $load, ?string $transferStatus = null): string
    {
        if ($load->state !== 'open') {
            return $load->state;
        }

        if ($load->transfer_id === null) {
            return 'released';
        }

        $transferStatus ??= $this->transfers->statuses([$load->transfer_id])[$load->transfer_id] ?? null;

        return match ($transferStatus) {
            'pending' => 'awaiting_approval',
            'approved' => 'approved',
            'rejected' => 'rejected',
            'cancelled' => 'cancelled',
            'in_transit', 'received' => 'released',
            default => 'awaiting_approval',
        };
    }

    /** @param iterable<Load> $loads @return array<string, string> load id => status, the transfers read once */
    public function statuses(iterable $loads): array
    {
        $loads = collect($loads);
        $transfers = $this->transfers->statuses($loads->pluck('transfer_id')->filter()->all());

        return $loads->mapWithKeys(fn (Load $load) => [$load->id => $this->status($load, $load->transfer_id === null ? null : ($transfers[$load->transfer_id] ?? 'pending'))])->all();
    }

    public function release(Load $load, User $by): Load
    {
        return Errors::transaction(function () use ($load, $by) {
            $load = Load::query()->whereKey($load->id)->lockForUpdate()->firstOrFail();

            if ($load->state !== 'open' || $load->released_at !== null || $load->transfer_id === null) {
                throw Errors::make(409, 'load_not_open');
            }

            if ($this->status($load) !== 'approved') {
                throw Errors::make(409, 'load_not_approved');
            }

            $this->transfers->dispatch($by, $load->transfer_id);
            $load->forceFill(['released_at' => CarbonImmutable::now()])->save();

            return $load;
        });
    }

    public function cancel(Load $load, User $by, string $reason): Load
    {
        return Errors::transaction(function () use ($load, $by, $reason) {
            $load = Load::query()->whereKey($load->id)->lockForUpdate()->firstOrFail();

            if ($load->state !== 'open' || ($load->transfer_id !== null && $load->released_at !== null)) {
                throw Errors::make(409, 'load_not_open');
            }

            if ($load->transfer_id !== null && in_array($this->status($load), ['awaiting_approval', 'approved'], true)) {
                $this->transfers->cancel($by, $load->transfer_id, $reason);
            }

            $load->forceFill(['state' => 'cancelled', 'cancelled_at' => CarbonImmutable::now(), 'note' => trim(($load->note ? $load->note."\n" : '').$reason)])->save();

            return $load;
        });
    }

    /**
     * VAN-08: the rep counts what arrived. A shortfall is written off in
     * transit by Inventory. Confirmed once; the same call again answers the
     * confirmed sheet.
     *
     * @param  list<array{id: string, qty_received: string}>  $received
     */
    public function confirm(Load $load, User $rep, array $received): Load
    {
        return Errors::transaction(function () use ($load, $rep, $received) {
            $load = Load::query()->whereKey($load->id)->lockForUpdate()->firstOrFail();

            if ($load->state === 'confirmed') {
                return $load;
            }

            if ($load->state !== 'open' || $this->status($load) !== 'released') {
                throw Errors::make(409, 'load_not_released');
            }

            $lines = LoadLine::query()->where('load_id', $load->id)->get()->keyBy('id');
            $counted = [];

            foreach ($received as $index => $row) {
                $line = $lines->get($row['id']) ?? throw Errors::make(422, 'load_line_unknown', field: "lines.{$index}.id");
                $qty = Qty::of((string) $row['qty_received']);

                if ($qty->isNegative() || $qty->isGreaterThan(BigDecimal::of((string) $line->qty))) {
                    throw Errors::make(422, 'load_received_invalid', field: "lines.{$index}.qty_received");
                }

                $counted[$line->id] = $qty;
            }

            if ($load->transfer_id !== null) {
                $this->transfers->receive($rep, $load->transfer_id,
                    $lines->map(fn (LoadLine $l) => ['item_id' => $l->item_id, 'qty_received' => (string) Qty::show($counted[$l->id] ?? Qty::of((string) $l->qty))])->values()->all(),
                    __('van_sales.loads.short_reason'));
            }

            foreach ($lines as $line) {
                $line->forceFill(['qty_confirmed' => (string) ($counted[$line->id] ?? Qty::of((string) $line->qty))])->save();
            }

            $load->forceFill([
                'state' => 'confirmed',
                'confirmed_by' => $rep->id,
                'confirmed_at' => CarbonImmutable::now(),
                // A sheet made ahead of the day joins the van's open run.
                'run_id' => $load->run_id ?? Run::query()->where('van_id', $load->van_id)->where('status', 'open')->value('id'),
            ])->save();

            return $load;
        });
    }
}
