<?php

namespace Modules\Inventory\Documents;

use App\Core\Identity\Models\User;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Inventory\Events\TransferReceived;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\TransferLine;
use Modules\Inventory\Stock\Places;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockInput;
use Modules\Inventory\Stock\StockLedger;
use Modules\Inventory\Stock\Valuer;

/**
 * INV-08: transfers between two locations of one company.
 *
 * - create(): checked lines (stock items, units, batches and serials at
 *   the source), numbered, valued at the company's cost, and the
 *   `inventory.transfer` flow started, in one transaction (pending).
 * - completed() / cancelled(): the flow decided it (approved or
 *   rejected) or was cancelled.
 * - dispatch(): approved → in transit: stock leaves the source
 *   (`transfer_out`), serials go in transit.
 * - receive(): in transit → received: what arrived enters the destination
 *   (`transfer_in`); the rest is written off from transit
 *   (`transfer_loss`, a cost) with a reason; TransferReceived carries the
 *   values.
 * - cancel(): a pending (its flow cancelled) or approved transfer.
 *
 * The caller (a Form Request) checked permissions and the input's shape.
 * Documents are audited before stock moves (the ledger's lock order).
 */
class Transfers
{
    public function __construct(
        private readonly StockInput $input,
        private readonly StockLedger $ledger,
        private readonly WorkflowEngine $engine,
        private readonly DocumentNumbers $numbers,
        private readonly Places $places,
        private readonly Valuer $valuer,
    ) {}

    /** @param array{from_location_id: string, to_location_id: string, note?: ?string, lines: list<array<string, mixed>>} $data */
    public function create(array $data, User $by): Transfer
    {
        $from = $this->place($data['from_location_id'], 'from_location_id');
        $to = $this->place($data['to_location_id'], 'to_location_id');

        if ($data['from_location_id'] === $data['to_location_id']) {
            throw StockLedger::error(422, 'same_location', field: 'to_location_id');
        }

        if ($from['company_id'] !== $to['company_id']) {
            throw StockLedger::error(422, 'transfer_cross_company', field: 'to_location_id');
        }

        $lines = $this->input->lines($data['lines'], 'lines', fn () => false, $data['from_location_id']);
        $currency = $this->valuer->currency($from['company_id']);
        $value = BigInteger::zero();

        foreach ($lines as $line) {
            $value = $value->plus($this->valuer->value($from['company_id'], $line->item->id, $line->qtyBase));
        }

        return StockInput::transaction(function () use ($data, $by, $from, $lines, $currency, $value) {
            $transfer = Transfer::create([
                'number' => $this->numbers->next(TransferType::KEY, $from['company_id'], $from['branch_id'], $data['from_location_id']),
                'company_id' => $from['company_id'],
                'branch_id' => $from['branch_id'],
                'from_location_id' => $data['from_location_id'],
                'to_location_id' => $data['to_location_id'],
                'status' => Transfer::PENDING,
                'note' => $data['note'] ?? null,
                'requested_by' => $by->id,
                'value_minor' => (string) $value,
                'currency' => $currency,
            ]);

            foreach ($lines as $index => $line) {
                TransferLine::create([
                    'transfer_id' => $transfer->id,
                    'line_no' => $index + 1,
                    'item_id' => $line->item->id,
                    'uom_id' => $line->uomId,
                    'qty' => (string) Qty::of($line->qty),
                    'qty_base' => (string) $line->qtyBase,
                    'batch_id' => $line->batchId,
                    'serials' => $line->serials,
                ]);
            }

            $this->engine->start(TransferType::KEY, $transfer->id, $by);

            return $transfer->refresh();
        });
    }

    /** WF-10: the transfer's flow ended; approved transfers wait for dispatch. */
    public function completed(string $transferId, string $outcome, ?string $userId): void
    {
        StockInput::transaction(function () use ($transferId, $outcome, $userId) {
            $transfer = Transfer::query()->whereKey($transferId)->lockForUpdate()->first();

            if ($transfer === null || $transfer->status !== Transfer::PENDING) {
                return;
            }

            $transfer->fill([
                'status' => $outcome === 'approved' ? Transfer::APPROVED : Transfer::REJECTED,
                'decided_by' => $userId,
                'decided_at' => CarbonImmutable::now(),
            ])->save();
        });
    }

    /** WF-11: the transfer's flow was cancelled. */
    public function cancelled(string $transferId): void
    {
        StockInput::transaction(function () use ($transferId) {
            $transfer = Transfer::query()->whereKey($transferId)->lockForUpdate()->first();

            if ($transfer !== null && $transfer->status === Transfer::PENDING) {
                $transfer->fill(['status' => Transfer::CANCELLED, 'cancelled_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    public function dispatch(Transfer $transfer, User $by): Transfer
    {
        return StockInput::transaction(function () use ($transfer, $by) {
            $transfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($transfer, [Transfer::APPROVED]);
            $this->place($transfer->from_location_id, 'from_location_id');
            $now = CarbonImmutable::now();

            $transfer->fill(['status' => Transfer::IN_TRANSIT, 'dispatched_by' => $by->id, 'dispatched_at' => $now])->save();
            $lines = [];

            foreach ($transfer->lines()->get() as $line) {
                $lines[] = new PostingLine(
                    $line->item_id, $transfer->from_location_id, Movement::TRANSFER_OUT, Qty::of($line->qty_base)->negated(),
                    sourceLineId: $line->id, batchId: $line->batch_id, uomId: $line->uom_id, uomQty: Qty::of($line->qty),
                    serialIds: $this->input->serialIds($line->item_id, $line->serials ?? [], false, $transfer->from_location_id),
                );
            }

            $this->ledger->post(new Posting($transfer->company_id, 'inventory.transfer', $transfer->id, $now, $by->id, $lines));

            return $transfer->refresh();
        });
    }

    /**
     * @param  list<array{id: string, qty_received: string}>|null  $received  per line, in the line's unit (all when null)
     */
    public function receive(Transfer $transfer, User $by, ?array $received, ?string $lossReason): Transfer
    {
        $result = StockInput::transaction(function () use ($transfer, $by, $received, $lossReason) {
            $transfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($transfer, [Transfer::IN_TRANSIT]);
            $this->place($transfer->to_location_id, 'to_location_id');
            $lines = $transfer->lines()->get()->keyBy('id');
            $arrived = $this->arrived($lines->all(), $received);
            $now = CarbonImmutable::now();

            $transfer->fill(['status' => Transfer::RECEIVED, 'received_by' => $by->id, 'received_at' => $now, 'loss_reason' => $lossReason])->save();
            $out = Movement::query()->where('source_type', 'inventory.transfer')->where('source_id', $transfer->id)->where('kind', Movement::TRANSFER_OUT)->get()->keyBy('source_line_id');
            $posting = [];

            foreach ($lines as $line) {
                $base = $arrived[$line->id];
                $lost = Qty::of(BigDecimal::of((string) $line->qty_base)->minus($base));
                $factor = BigDecimal::of((string) $line->qty)->dividedBy((string) $line->qty_base, 12, RoundingMode::HalfUp);
                $line->forceFill(['qty_received' => (string) Qty::of($base->multipliedBy($factor)), 'qty_lost' => (string) Qty::of($lost->multipliedBy($factor))])->save();
                $serials = $out->get($line->id)?->serials()->pluck('inventory_serials.id')->all() ?? [];

                if ($base->isPositive()) {
                    $posting[] = new PostingLine($line->item_id, $transfer->to_location_id, Movement::TRANSFER_IN, $base,
                        sourceLineId: $line->id, batchId: $line->batch_id, serialIds: $serials);
                }

                if ($lost->isPositive()) {
                    $posting[] = new PostingLine($line->item_id, null, Movement::TRANSFER_LOSS, $lost->negated(),
                        sourceLineId: $line->id, batchId: $line->batch_id);
                }
            }

            $movements = $this->ledger->post(new Posting($transfer->company_id, 'inventory.transfer', $transfer->id, $now, $by->id, $posting));
            $value = fn (string $kind) => array_reduce($movements, fn (BigInteger $sum, Movement $m) => $m->kind === $kind ? $sum->plus(BigInteger::of((string) $m->value_minor)->abs()) : $sum, BigInteger::zero());

            return [$transfer->refresh(), $movements === [] ? null : $movements[0]->currency, $value(Movement::TRANSFER_IN), $value(Movement::TRANSFER_LOSS)];
        });

        [$transfer, $currency, $in, $lost] = $result;
        TransferReceived::dispatch($transfer->tenant_id, $transfer->id, $transfer->company_id, $transfer->from_location_id, $transfer->to_location_id,
            $currency ?? $this->valuer->currency($transfer->company_id), (string) $in, (string) $lost);

        return $transfer;
    }

    public function cancel(Transfer $transfer, User $by, string $reason): Transfer
    {
        return StockInput::transaction(function () use ($transfer, $by, $reason) {
            $transfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($transfer, [Transfer::PENDING, Transfer::APPROVED]);

            if ($transfer->status === Transfer::PENDING) {
                $workflow = $this->engine->current(TransferType::KEY, $transfer->id);

                if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                    // The module cancels its own flow; the transfer's audit entry names the person.
                    $this->engine->cancel($workflow, null, $reason);
                }
            }

            $transfer->fill(['status' => Transfer::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            return $transfer->refresh();
        });
    }

    /**
     * Base quantities that arrived per line: everything when not given;
     * otherwise each named line's quantity (in its unit), at most what was
     * dispatched; serial lines arrive in full.
     *
     * @param  array<string, TransferLine>  $lines
     * @param  list<array{id: string, qty_received: string}>|null  $received
     * @return array<string, BigDecimal>
     */
    private function arrived(array $lines, ?array $received): array
    {
        $arrived = [];

        foreach ($lines as $id => $line) {
            $arrived[$id] = Qty::of((string) $line->qty_base);
        }

        foreach (array_values($received ?? []) as $index => $row) {
            $line = $lines[$row['id']] ?? null;

            if ($line === null) {
                throw StockLedger::error(422, 'line_unknown', field: "lines.{$index}.id");
            }

            try {
                $qty = BigDecimal::of((string) $row['qty_received']);
            } catch (MathException) {
                throw StockLedger::error(422, 'qty_invalid', field: "lines.{$index}.qty_received");
            }

            if ($qty->isNegative() || $qty->isGreaterThan((string) $line->qty)) {
                throw StockLedger::error(422, 'receive_too_much', field: "lines.{$index}.qty_received");
            }

            if (($line->serials ?? []) !== [] && ! $qty->isEqualTo((string) $line->qty)) {
                throw StockLedger::error(422, 'serials_partial', field: "lines.{$index}.qty_received");
            }

            $arrived[$line->id] = Qty::of($qty->multipliedBy((string) $line->qty_base)->dividedBy((string) $line->qty, 12, RoundingMode::HalfUp));
        }

        return $arrived;
    }

    /** @return array{company_id: string, branch_id: string, archived: bool} */
    private function place(string $locationId, string $field): array
    {
        $place = $this->places->of($locationId) ?? throw StockLedger::error(422, 'location_unknown', field: $field);

        if ($place['archived']) {
            throw StockLedger::error(422, 'location_archived', field: $field);
        }

        return $place;
    }

    /** @param list<string> $statuses */
    private function assertStatus(Transfer $transfer, array $statuses): void
    {
        if (! in_array($transfer->status, $statuses, true)) {
            throw StockLedger::error(409, 'document_not_open');
        }
    }
}
