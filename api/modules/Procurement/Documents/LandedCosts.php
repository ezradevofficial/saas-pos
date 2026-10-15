<?php

namespace Modules\Procurement\Documents;

use App\Core\Currency\BaseCurrencyLock;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Procurement\Events\LandedCostAllocated;
use Modules\Procurement\Models\LandedCost;
use Modules\Procurement\Models\LandedCostAllocation;
use Modules\Procurement\Models\LandedCostCharge;
use Modules\Procurement\Models\LandedCostReceipt;
use Modules\Procurement\Models\OrderLine;
use Modules\Procurement\Models\Receipt;
use Modules\Procurement\Models\ReceiptLine;
use Modules\Procurement\Support\LineResolver;
use Modules\Procurement\Support\Places;
use Modules\Procurement\Support\Pricing;
use Modules\Procurement\Support\ProcurementError;
use Modules\Procurement\Support\Tx;

/**
 * PRC-17: landed costs.
 *
 * - create() / update(): a draft with charges (any active currency, the
 *   charge's supplier and the supplier's bill reference, both required to
 *   post: decision 19) and the GRNs (draft or posted, of the company) they
 *   apply to, and a method (`value`, `quantity`, `weight`).
 * - preview(): the allocation the document would post now.
 * - post(): each charge converted to base at the company's rate on the
 *   document date (CUR-04), the total split over the GRNs' lines with an
 *   accepted quantity (LandedCostAllocator: integer minor units, largest
 *   remainder, nothing lost): by base value (a posted line's base value;
 *   a draft's PO value at the document date's rate), by accepted quantity
 *   in the item's base unit, or by weight (`weight_missing` when a line has
 *   none). A posted GRN's lines take their share at once; a draft takes it
 *   when it posts (Receipts::post). Then LandedCostAllocated.
 * - cancel(): a draft.
 */
class LandedCosts
{
    public function __construct(
        private readonly LineResolver $resolver,
        private readonly Pricing $pricing,
        private readonly DocumentNumbers $numbers,
        private readonly BaseCurrencyLock $baseLock,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $by): LandedCost
    {
        return Tx::run(function () use ($data, $by) {
            $branch = Branch::query()->findOrFail($data['branch_id']);
            $landedCost = LandedCost::query()->create([
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'cost_date' => $data['cost_date'],
                'method' => $data['method'],
                'description' => $data['description'] ?? null,
                'status' => LandedCost::DRAFT,
                'created_by' => $by->id,
            ]);
            $this->writeCharges($landedCost, $data['charges']);
            $this->writeReceipts($landedCost, $data['receipt_ids'], $by);

            return $landedCost->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(LandedCost $landedCost, array $data, User $by): LandedCost
    {
        return Tx::run(function () use ($landedCost, $data, $by) {
            $landedCost = LandedCost::query()->whereKey($landedCost->id)->lockForUpdate()->firstOrFail();

            if ($landedCost->status !== LandedCost::DRAFT) {
                throw ProcurementError::make('document_not_draft', status: 409);
            }

            foreach (['cost_date', 'method', 'description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $landedCost->{$field} = $data[$field];
                }
            }

            $landedCost->save();

            if (isset($data['charges'])) {
                LandedCostCharge::query()->where('landed_cost_id', $landedCost->id)->delete();
                $this->writeCharges($landedCost, $data['charges']);
            }

            if (isset($data['receipt_ids'])) {
                LandedCostReceipt::query()->where('landed_cost_id', $landedCost->id)->delete();
                $this->writeReceipts($landedCost, $data['receipt_ids'], $by);
            }

            return $landedCost->refresh();
        });
    }

    /**
     * The allocation the document would post now, or what it posted.
     *
     * @return array{base_currency: ?string, total_minor: ?int, lines: list<array{receipt_line: ReceiptLine, basis: string, amount_minor: int}>, error: ?string}
     */
    public function preview(LandedCost $landedCost): array
    {
        if ($landedCost->status === LandedCost::POSTED) {
            $allocations = LandedCostAllocation::query()->with('receiptLine.receipt', 'receiptLine.item')->where('landed_cost_id', $landedCost->id)->get();

            return [
                'base_currency' => $landedCost->base_currency,
                'total_minor' => $landedCost->base_total_minor,
                'lines' => $allocations->map(fn (LandedCostAllocation $a) => ['receipt_line' => $a->receiptLine, 'basis' => (string) $a->basis, 'amount_minor' => $a->base_amount_minor])->all(),
                'error' => null,
                'message' => null,
            ];
        }

        try {
            $company = Company::query()->findOrFail($landedCost->company_id);
            [$total] = $this->charges($company, $landedCost, persist: false);
            $plan = $this->plan($company, $landedCost, $total);

            return ['base_currency' => $company->base_currency, 'total_minor' => $total, 'lines' => $plan, 'error' => null, 'message' => null];
        } catch (ApiException $e) {
            return ['base_currency' => null, 'total_minor' => null, 'lines' => [], 'error' => $e->errorCode, 'message' => $e->getMessage()];
        }
    }

    public function post(LandedCost $landedCost, User $by): LandedCost
    {
        $event = null;

        $posted = Tx::run(function () use ($landedCost, $by, &$event) {
            $landedCost = LandedCost::query()->whereKey($landedCost->id)->lockForUpdate()->firstOrFail();

            if ($landedCost->status !== LandedCost::DRAFT) {
                throw ProcurementError::make('document_not_draft', status: 409);
            }

            $company = Company::query()->findOrFail($landedCost->company_id);
            $base = $this->baseLock->lock($company);
            // The GRNs first, in a fixed order: a GRN posting at the same time waits or is seen posted.
            $receipts = Receipt::query()->whereIn('id', LandedCostReceipt::query()->where('landed_cost_id', $landedCost->id)->select('receipt_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($receipts->contains(fn (Receipt $receipt) => $receipt->status === Receipt::CANCELLED)) {
                throw ProcurementError::make('receipt_cancelled', field: 'receipt_ids');
            }

            // Decision 19: each charge names its supplier and the supplier's bill, which Accounting posts.
            foreach (LandedCostCharge::query()->where('landed_cost_id', $landedCost->id)->orderBy('line_no')->get() as $index => $charge) {
                if ($charge->party_id === null) {
                    throw ProcurementError::make('charge_supplier_required', ['line' => $charge->line_no], "charges.{$index}.party_id");
                }

                if (trim((string) $charge->bill_reference) === '') {
                    throw ProcurementError::make('charge_bill_required', ['line' => $charge->line_no], "charges.{$index}.bill_reference");
                }
            }

            [$total] = $this->charges($company, $landedCost, persist: true);
            $plan = $this->plan($company, $landedCost, $total);

            foreach ($plan as $part) {
                $line = $part['receipt_line'];
                $receipt = $receipts->get($line->receipt_id);
                $after = $receipt->status === Receipt::POSTED;
                LandedCostAllocation::query()->create([
                    'landed_cost_id' => $landedCost->id,
                    'receipt_id' => $receipt->id,
                    'receipt_line_id' => $line->id,
                    'basis' => $part['basis'],
                    'base_amount_minor' => $part['amount_minor'],
                    'after_receipt' => $after,
                ]);

                if ($after) {
                    $line->fill(['landed_minor' => $line->landed_minor + $part['amount_minor']])->save();
                    $receipt->fill(['landed_minor' => $receipt->landed_minor + $part['amount_minor']])->save();
                }
            }

            $landedCost->fill([
                'number' => $landedCost->number ?? $this->numbers->next(DocumentNumbers::LANDED_COST, $landedCost->company_id, $landedCost->branch_id, at: $landedCost->cost_date->setTime(12, 0)),
                'status' => LandedCost::POSTED,
                'base_currency' => $base,
                'base_total_minor' => $total,
                'posted_by' => $by->id,
                'posted_at' => CarbonImmutable::now(),
            ])->save();

            $event = $this->allocatedEvent($landedCost);

            return $landedCost->refresh();
        });

        event($event);

        return $posted;
    }

    /**
     * PRC-17, PRC-25: LandedCostAllocated of a posted landed cost, from what
     * it stored (also replayed for the Accounting backfill, ACC-29).
     * `amounts` cover the allocations to GRNs already posted: `stock_value`
     * those to stock lines Inventory tracked (a cost adjustment carries
     * them), `expense` the others (services, untracked stock), and
     * `landed_cost` their sum; each allocation says whether its line was
     * tracked (`tracked`); each charge its supplier's bill reference.
     */
    public function allocatedEvent(LandedCost $landedCost): LandedCostAllocated
    {
        $charges = LandedCostCharge::query()->where('landed_cost_id', $landedCost->id)->orderBy('line_no')->get();
        $allocations = LandedCostAllocation::query()->with('receiptLine.receipt')->where('landed_cost_id', $landedCost->id)->orderBy('id')->get();
        $items = Item::query()->whereKey($allocations->pluck('receiptLine.item_id')->filter()->unique()->all())->get()->keyBy('id');
        $amounts = ['stock_value' => 0, 'expense' => 0, 'landed_cost' => 0];
        $rows = [];

        foreach ($allocations as $allocation) {
            $line = $allocation->receiptLine;
            $stock = $line->item_id !== null && $items->get($line->item_id)?->type === 'stock';
            $after = (bool) $allocation->after_receipt;
            $tracked = $stock && $after && (bool) $line->stock_tracked;

            if ($after) {
                $amounts[$tracked ? 'stock_value' : 'expense'] += (int) $allocation->base_amount_minor;
                $amounts['landed_cost'] += (int) $allocation->base_amount_minor;
            }

            $rows[] = [
                'receipt_id' => $allocation->receipt_id, 'receipt_line_id' => $line->id, 'item_id' => $line->item_id, 'location_id' => $line->receipt->location_id,
                'stock' => $stock, 'after_receipt' => $after, 'tracked' => $tracked, 'base_amount_minor' => (string) $allocation->base_amount_minor,
            ];
        }

        return new LandedCostAllocated((string) $landedCost->tenant_id, $landedCost->id, $landedCost->company_id, $landedCost->branch_id,
            $landedCost->cost_date->toDateString(), (string) $landedCost->base_currency, array_map(fn (int $amount) => (string) $amount, $amounts),
            $charges->map(fn (LandedCostCharge $charge) => [
                'id' => $charge->id, 'line_no' => $charge->line_no, 'description' => $charge->description, 'party_id' => $charge->party_id,
                'bill_reference' => $charge->bill_reference, 'currency' => $charge->currency, 'amount_minor' => (string) $charge->amount_minor,
                'fx' => $charge->fx?->jsonSerialize(), 'base_amount_minor' => (string) $charge->base_amount_minor,
            ])->values()->all(),
            $rows, (string) $landedCost->number);
    }

    public function cancel(LandedCost $landedCost, User $by, string $reason): LandedCost
    {
        return Tx::run(function () use ($landedCost, $by, $reason) {
            $landedCost = LandedCost::query()->whereKey($landedCost->id)->lockForUpdate()->firstOrFail();

            if ($landedCost->status !== LandedCost::DRAFT) {
                throw ProcurementError::make('document_not_draft', status: 409);
            }

            $landedCost->fill(['status' => LandedCost::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            return $landedCost->refresh();
        });
    }

    /**
     * The charges' base total at the document date's rate.
     *
     * @return array{0: int, 1: Collection<int, LandedCostCharge>}
     */
    private function charges(Company $company, LandedCost $landedCost, bool $persist): array
    {
        $charges = LandedCostCharge::query()->where('landed_cost_id', $landedCost->id)->orderBy('line_no')->get();

        if ($charges->isEmpty()) {
            throw ProcurementError::make('document_empty', field: 'charges');
        }

        $at = Pricing::endOfDay($company, $landedCost->cost_date);
        $total = 0;

        foreach ($charges as $charge) {
            $fx = $this->pricing->fx($company, $charge->currency, $at);
            $amount = Pricing::convert($fx, $charge->amount_minor, $charge->currency);
            $total += $amount;

            if ($persist) {
                $charge->fill(['fx' => $fx, 'base_amount_minor' => $amount])->save();
            }
        }

        return [$total, $charges];
    }

    /**
     * The split of $total over the GRNs' accepted lines by the method.
     *
     * @return list<array{receipt_line: ReceiptLine, basis: string, amount_minor: int}>
     */
    private function plan(Company $company, LandedCost $landedCost, int $total): array
    {
        $receiptIds = LandedCostReceipt::query()->where('landed_cost_id', $landedCost->id)->pluck('receipt_id');
        $lines = ReceiptLine::query()->with('receipt')->whereIn('receipt_id', $receiptIds)
            ->whereIn('receipt_id', fn ($q) => $q->select('id')->from('procurement_receipts')->where('status', '<>', 'cancelled'))
            ->where('accepted_qty', '>', 0)->orderBy('receipt_id')->orderBy('line_no')->get();

        if ($lines->isEmpty()) {
            throw ProcurementError::make('receipts_required', field: 'receipt_ids');
        }

        $bases = [];

        foreach ($lines as $index => $line) {
            $bases[$line->id] = match ($landedCost->method) {
                'value' => (string) $this->value($company, $landedCost, $line),
                'quantity' => $this->baseQty($line),
                'weight' => $line->weight === null ? throw ProcurementError::make('weight_missing', ['line' => $line->receipt->number ? "{$line->receipt->number} / {$line->line_no}" : $line->line_no], 'receipt_ids') : (string) $line->weight,
            };
        }

        if (collect($bases)->every(fn (string $basis) => BigDecimal::of($basis)->isZero())) {
            throw ProcurementError::make('allocation_basis_zero', field: 'method');
        }

        $shares = LandedCostAllocator::allocate($total, $bases, $company->base_currency);

        return $lines->map(fn (ReceiptLine $line) => ['receipt_line' => $line, 'basis' => $bases[$line->id], 'amount_minor' => $shares[$line->id]])->values()->all();
    }

    /** A line's base value: a posted GRN's, else its PO value at the document date's rate. */
    private function value(Company $company, LandedCost $landedCost, ReceiptLine $line): int
    {
        if ($line->receipt->status === Receipt::POSTED) {
            return (int) $line->base_value_minor;
        }

        $orderLine = OrderLine::query()->findOrFail($line->order_line_id);
        $value = (int) (string) BigDecimal::of($orderLine->net_minor)->multipliedBy((string) $line->accepted_qty)->dividedBy((string) $orderLine->qty, 0, RoundingMode::HalfUp);
        $fx = $this->pricing->fx($company, $line->receipt->currency, Pricing::endOfDay($company, $landedCost->cost_date));

        return Pricing::convert($fx, $value, $line->receipt->currency);
    }

    /** The accepted quantity in the item's base unit (free-text lines: as entered). */
    private function baseQty(ReceiptLine $line): string
    {
        $qty = BigDecimal::of((string) $line->accepted_qty);

        if ($line->item_id === null || $line->uom_id === null) {
            return (string) $qty;
        }

        $item = Item::query()->find($line->item_id);

        if ($item === null || $item->base_uom_id === $line->uom_id) {
            return (string) $qty;
        }

        $factor = ItemUom::query()->where('item_id', $item->id)->where('uom_id', $line->uom_id)->value('factor') ?? '1';

        return (string) $qty->multipliedBy((string) $factor)->toScale(6, RoundingMode::HalfUp);
    }

    /** @param list<array<string, mixed>> $charges */
    private function writeCharges(LandedCost $landedCost, array $charges): void
    {
        foreach (array_values($charges) as $index => $input) {
            LandedCostCharge::query()->create([
                'landed_cost_id' => $landedCost->id,
                'line_no' => $index + 1,
                'description' => $input['description'],
                'party_id' => empty($input['party_id']) ? null : $this->resolver->supplier($landedCost->company_id, $input['party_id'], "charges.{$index}.party_id")->id,
                'bill_reference' => isset($input['bill_reference']) && trim((string) $input['bill_reference']) !== '' ? trim((string) $input['bill_reference']) : null,
                'currency' => $this->resolver->currency($input['currency'], "charges.{$index}.currency"),
                'amount_minor' => (int) $input['amount_minor'],
            ]);
        }
    }

    /**
     * PRC-22: GRNs of the company the user reaches: `procurement.receipt.view`
     * or `procurement.landed_cost.create` at the GRN's location.
     *
     * @param  list<string>  $receiptIds
     */
    private function writeReceipts(LandedCost $landedCost, array $receiptIds, User $by): void
    {
        $places = app(Places::class);

        foreach (array_values(array_unique($receiptIds)) as $index => $receiptId) {
            $receipt = Receipt::query()->where('company_id', $landedCost->company_id)->whereKey($receiptId)->where('status', '<>', Receipt::CANCELLED)->first();

            if ($receipt === null || (! $places->atLocation($by, 'procurement.receipt.view', $receipt->location_id) && ! $places->atLocation($by, 'procurement.landed_cost.create', $receipt->location_id))) {
                throw ProcurementError::make('receipt_unknown', field: "receipt_ids.{$index}");
            }

            LandedCostReceipt::query()->create(['landed_cost_id' => $landedCost->id, 'receipt_id' => $receipt->id]);
        }
    }
}
