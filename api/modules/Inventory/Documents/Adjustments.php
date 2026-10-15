<?php

namespace Modules\Inventory\Documents;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Modules\Inventory\Events\StockAdjusted;
use Modules\Inventory\Models\Adjustment;
use Modules\Inventory\Models\AdjustmentLine;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Places;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockInput;
use Modules\Inventory\Stock\StockLedger;
use Modules\Inventory\Stock\Valuer;

/**
 * INV-10: stock adjustments with a reason, posted on approval.
 *
 * - create(): checked lines (a signed quantity each: positive adds, with a
 *   batch number and expiry, new serials and an optional unit cost;
 *   negative takes out, from a batch and serials in stock), numbered,
 *   valued (in and out, at the company's cost or the typed cost) and the
 *   `inventory.adjustment` flow started, in one transaction (pending).
 * - completed(): approved: posted (`adjustment_in`, `adjustment_out`) and
 *   StockAdjusted raised; when stock moved meanwhile and the posting is
 *   refused (no negative stock, a count running), the adjustment is
 *   `failed` with the reason; rejected: `rejected`.
 * - cancel(): a pending adjustment, its flow cancelled.
 */
class Adjustments
{
    public function __construct(
        private readonly StockInput $input,
        private readonly StockLedger $ledger,
        private readonly WorkflowEngine $engine,
        private readonly DocumentNumbers $numbers,
        private readonly Places $places,
        private readonly Valuer $valuer,
    ) {}

    /** @param array{location_id: string, reason: string, note?: ?string, lines: list<array<string, mixed>>} $data */
    public function create(array $data, User $by): Adjustment
    {
        $place = $this->places->of($data['location_id']) ?? throw StockLedger::error(422, 'location_unknown', field: 'location_id');

        if ($place['archived']) {
            throw StockLedger::error(422, 'location_archived', field: 'location_id');
        }

        $lines = $this->input->for($by)->lines($data['lines'], 'lines', fn (int $index, $qty) => $qty->isPositive(), $data['location_id'], signed: true);
        StockLedger::assertNotFrozen($data['location_id'], array_map(fn ($line) => $line->item->id, $lines));
        $currency = $this->valuer->currency($place['company_id']);
        $in = BigInteger::zero();
        $out = BigInteger::zero();

        foreach ($lines as $index => $line) {
            if ($line->qty->isPositive()) {
                $cost = $data['lines'][$index]['unit_cost_minor'] ?? null;

                // ADR 003: a typed cost is in the company's base currency (the stock's valuation currency).
                if ($cost !== null && ($data['lines'][$index]['unit_cost_currency'] ?? null) !== $currency) {
                    throw StockLedger::error(422, 'unit_cost_currency', ['currency' => $currency], "lines.{$index}.unit_cost_currency");
                }

                $typed = $this->valuer->minorCost($cost, $line->qty);
                $in = $in->plus($typed ?? $this->valuer->value($place['company_id'], $line->item->id, $line->qtyBase));
            } else {
                $out = $out->plus($this->valuer->value($place['company_id'], $line->item->id, $line->qtyBase));
            }
        }

        return StockInput::transaction(function () use ($data, $by, $place, $lines, $currency, $in, $out) {
            $adjustment = Adjustment::create([
                'number' => $this->numbers->next(AdjustmentType::KEY, $place['company_id'], $place['branch_id'], $data['location_id']),
                'company_id' => $place['company_id'],
                'branch_id' => $place['branch_id'],
                'location_id' => $data['location_id'],
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
                'status' => Adjustment::PENDING,
                'requested_by' => $by->id,
                'value_in_minor' => (string) $in,
                'value_out_minor' => (string) $out,
                'currency' => $currency,
            ]);

            foreach ($lines as $index => $line) {
                $sign = $line->qty->isNegative() ? '-' : '';
                AdjustmentLine::create([
                    'adjustment_id' => $adjustment->id,
                    'line_no' => $index + 1,
                    'item_id' => $line->item->id,
                    'uom_id' => $line->uomId,
                    'qty' => (string) Qty::of($line->qty),
                    'qty_base' => $sign.Qty::of($line->qtyBase),
                    'batch_id' => $line->batchId,
                    'batch_number' => $line->batchId === null ? $line->batchNumber : null,
                    'expires_on' => $line->batchId === null ? $line->expiresOn : null,
                    'serials' => $line->serials,
                    'unit_cost_minor' => $cost = $line->qty->isPositive() ? ($data['lines'][$index]['unit_cost_minor'] ?? null) : null,
                    'unit_cost_currency' => $cost === null ? null : $currency,
                ]);
            }

            $this->engine->start(AdjustmentType::KEY, $adjustment->id, $by);

            return $adjustment->refresh();
        });
    }

    /** WF-10: approved adjustments are posted; rejected ones end. */
    public function completed(string $adjustmentId, string $outcome, ?string $userId): void
    {
        if ($outcome !== 'approved') {
            StockInput::transaction(function () use ($adjustmentId, $userId) {
                $adjustment = Adjustment::query()->whereKey($adjustmentId)->lockForUpdate()->first();

                if ($adjustment !== null && $adjustment->status === Adjustment::PENDING) {
                    $adjustment->fill(['status' => Adjustment::REJECTED, 'decided_by' => $userId, 'decided_at' => CarbonImmutable::now()])->save();
                }
            });

            return;
        }

        $this->attempt($adjustmentId, $userId);
    }

    /**
     * L4: post approved adjustments that waited for a count at $locationId
     * to end (as their approver).
     */
    public function postWaiting(string $locationId): void
    {
        foreach (Adjustment::query()->where('status', Adjustment::WAITING)->where('location_id', $locationId)->orderBy('decided_at')->orderBy('id')->get() as $adjustment) {
            $this->attempt($adjustment->id, $adjustment->decided_by);
        }
    }

    /**
     * Post an approved (or waiting) adjustment. Refused because a count
     * froze its items: it waits (`waiting`) and is posted when the count
     * ends; refused for any other reason (stock moved meanwhile): `failed`.
     */
    private function attempt(string $adjustmentId, ?string $userId): void
    {
        try {
            $posted = StockInput::transaction(fn () => $this->post($adjustmentId, $userId));
        } catch (ApiException $e) {
            StockInput::transaction(function () use ($adjustmentId, $userId, $e) {
                $adjustment = Adjustment::query()->whereKey($adjustmentId)->lockForUpdate()->first();

                if ($adjustment !== null && in_array($adjustment->status, [Adjustment::PENDING, Adjustment::WAITING], true)) {
                    $waits = $e->errorCode === 'count_in_progress';
                    $adjustment->fill([
                        'status' => $waits ? Adjustment::WAITING : Adjustment::FAILED,
                        'failure' => $waits ? null : $e->errorCode,
                        'decided_by' => $userId,
                        'decided_at' => $adjustment->decided_at ?? CarbonImmutable::now(),
                    ])->save();
                }
            });

            return;
        }

        if ($posted !== null) {
            [$adjustment, $in, $out, $currency] = $posted;
            StockAdjusted::dispatch(
                $adjustment->tenant_id, $adjustment->id, $adjustment->company_id, $adjustment->location_id, $adjustment->reason, $currency, (string) $in, (string) $out,
                ($adjustment->posted_at ?? CarbonImmutable::now())->utc()->format('Y-m-d\TH:i:s.u\Z'), $adjustment->branch_id,
            );
        }
    }

    /** @return array{0: Adjustment, 1: BigInteger, 2: BigInteger, 3: string}|null */
    private function post(string $adjustmentId, ?string $userId): ?array
    {
        $companyId = Adjustment::query()->whereKey($adjustmentId)->value('company_id');

        if ($companyId !== null) {
            // L5: the company row (first posting) before the audit head.
            $this->ledger->baseCurrency($companyId);
        }

        $adjustment = Adjustment::query()->whereKey($adjustmentId)->lockForUpdate()->first();

        if ($adjustment === null || ! in_array($adjustment->status, [Adjustment::PENDING, Adjustment::WAITING], true)) {
            return null;
        }

        $now = CarbonImmutable::now();
        $adjustment->fill(['status' => Adjustment::POSTED, 'decided_by' => $userId, 'decided_at' => $adjustment->decided_at ?? $now, 'posted_at' => $now])->save();
        $lines = $adjustment->lines()->get();
        $posting = [];

        foreach ($lines as $line) {
            $qty = Qty::of((string) $line->qty_base);
            $coming = $qty->isPositive();
            $posting[] = new PostingLine(
                $line->item_id, $adjustment->location_id, $coming ? Movement::ADJUSTMENT_IN : Movement::ADJUSTMENT_OUT, $qty,
                sourceLineId: $line->id,
                batchId: $line->batch_id ?? ($line->batch_number === null ? null : $this->batch($line)),
                uomId: $line->uom_id,
                uomQty: Qty::of((string) $line->qty),
                cost: $coming ? $this->valuer->minorCost($line->unit_cost_minor === null ? null : (string) $line->unit_cost_minor, Qty::of((string) $line->qty)) : null,
                serialIds: $this->input->serialIds($line->item_id, $line->serials ?? [], $coming, $adjustment->location_id),
                setsLastCost: $coming && $line->unit_cost_minor !== null,
            );
        }

        $movements = $this->ledger->post(new Posting($adjustment->company_id, 'inventory.adjustment', $adjustment->id, $now, $userId, $posting, reference: $adjustment->number));
        $in = BigInteger::zero();
        $out = BigInteger::zero();

        foreach ($movements as $movement) {
            $value = BigInteger::of((string) $movement->value_minor);

            if ($value->isNegative()) {
                $out = $out->plus($value->abs());
            } else {
                $in = $in->plus($value);
            }

            AdjustmentLine::query()->whereKey($movement->source_line_id)->update(['value_minor' => (string) $value]);
        }

        return [$adjustment->refresh(), $in, $out, $movements[0]->currency ?? $adjustment->currency];
    }

    /** The batch a line coming in names by number, made on first use. */
    private function batch(AdjustmentLine $line): string
    {
        $existing = Batch::query()->where('item_id', $line->item_id)->whereRaw('lower(batch_number) = lower(?)', [$line->batch_number])->first();

        return $existing?->id ?? Batch::create(['item_id' => $line->item_id, 'batch_number' => $line->batch_number, 'expires_on' => $line->expires_on])->id;
    }

    /** WF-11: the adjustment's flow was cancelled. */
    public function cancelled(string $adjustmentId): void
    {
        StockInput::transaction(function () use ($adjustmentId) {
            $adjustment = Adjustment::query()->whereKey($adjustmentId)->lockForUpdate()->first();

            if ($adjustment !== null && $adjustment->status === Adjustment::PENDING) {
                $adjustment->fill(['status' => Adjustment::CANCELLED, 'cancelled_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    public function cancel(Adjustment $adjustment, User $by, string $reason): Adjustment
    {
        return StockInput::transaction(function () use ($adjustment, $by, $reason) {
            $adjustment = Adjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

            if ($adjustment->status !== Adjustment::PENDING) {
                throw StockLedger::error(409, 'document_not_open');
            }

            $workflow = $this->engine->current(AdjustmentType::KEY, $adjustment->id);

            if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                $this->engine->cancel($workflow, null, $reason);
            }

            $adjustment->fill(['status' => Adjustment::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            return $adjustment->refresh();
        });
    }
}
