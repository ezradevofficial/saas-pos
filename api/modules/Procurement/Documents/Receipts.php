<?php

namespace Modules\Procurement\Documents;

use App\Core\Currency\BaseCurrencyLock;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Procurement\Events\GoodsReceiptPosted;
use Modules\Procurement\Models\LandedCostAllocation;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Models\OrderLine;
use Modules\Procurement\Models\Receipt;
use Modules\Procurement\Models\ReceiptLine;
use Modules\Procurement\Models\Settings;
use Modules\Procurement\Support\LineResolver;
use Modules\Procurement\Support\Pricing;
use Modules\Procurement\Support\ProcurementError;
use Modules\Procurement\Support\Tx;

/**
 * PRC-14: goods received notes.
 *
 * - create() / update(): a draft against an approved PO, at a location of
 *   its company (the PO's delivery location by default), lines naming the
 *   PO's lines: received and rejected quantities, batch, expiry, serials,
 *   weight.
 * - post(): under the PO's lock, refused beyond the over-receipt allowance
 *   (`over_receipt`, on the accepted quantity); each line valued at the PO
 *   line's net unit price (net ÷ ordered quantity) for its accepted
 *   quantity, in base currency at the company's rate on the receipt date
 *   (CUR-04), plus the landed cost already allocated to it (PRC-17);
 *   on time when received by the PO's expected date (the company's time
 *   zone); numbered; the PO's quantities and status follow. Then, after
 *   commit, GoodsReceived (stock lines, Inventory active) and
 *   GoodsReceiptPosted (Accounting's payload).
 * - cancel(): a draft.
 */
class Receipts
{
    public function __construct(
        private readonly LineResolver $resolver,
        private readonly Pricing $pricing,
        private readonly DocumentNumbers $numbers,
        private readonly Orders $orders,
        private readonly BaseCurrencyLock $baseLock,
        private readonly ModuleRegistry $modules,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $by): Receipt
    {
        return Tx::run(function () use ($data, $by) {
            $order = Order::query()->whereKey($data['order_id'])->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, Order::RECEIVABLE, true)) {
                throw ProcurementError::make('po_not_approved', field: 'order_id');
            }

            $location = $this->location($order, $data['location_id'] ?? $order->location_id);
            $receipt = Receipt::query()->create([
                'company_id' => $order->company_id,
                'branch_id' => $location->branch_id,
                'location_id' => $location->id,
                'order_id' => $order->id,
                'party_id' => $order->party_id,
                'received_at' => $data['received_at'] ?? CarbonImmutable::now(),
                'delivery_note' => $data['delivery_note'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => Receipt::DRAFT,
                'currency' => $order->currency,
                'created_by' => $by->id,
            ]);
            $this->writeLines($order, $receipt, $data['lines']);

            return $receipt->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Receipt $receipt, array $data): Receipt
    {
        return Tx::run(function () use ($receipt, $data) {
            $receipt = Receipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if ($receipt->status !== Receipt::DRAFT) {
                throw ProcurementError::make('document_not_draft', status: 409);
            }

            $order = Order::query()->findOrFail($receipt->order_id);

            if (array_key_exists('location_id', $data)) {
                $location = $this->location($order, $data['location_id']);
                $receipt->fill(['location_id' => $location->id, 'branch_id' => $location->branch_id]);
            }

            foreach (['received_at', 'delivery_note', 'note'] as $field) {
                if (array_key_exists($field, $data)) {
                    $receipt->{$field} = $data[$field];
                }
            }

            $receipt->save();

            if (isset($data['lines'])) {
                if (LandedCostAllocation::query()->where('receipt_id', $receipt->id)->exists()) {
                    throw ProcurementError::make('receipt_has_landed_costs', field: 'lines');
                }

                ReceiptLine::query()->where('receipt_id', $receipt->id)->delete();
                $this->writeLines($order, $receipt, $data['lines']);
            }

            return $receipt->refresh();
        });
    }

    public function post(Receipt $receipt, User $by): Receipt
    {
        $events = Tx::run(function () use ($receipt, $by) {
            $receipt = Receipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if ($receipt->status !== Receipt::DRAFT) {
                throw ProcurementError::make('document_not_draft', status: 409);
            }

            $order = Order::query()->whereKey($receipt->order_id)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, Order::RECEIVABLE, true)) {
                throw ProcurementError::make('po_not_approved', status: 409);
            }

            $company = Company::query()->findOrFail($receipt->company_id);
            $base = $this->baseLock->lock($company);
            $fx = $this->pricing->fx($company, $receipt->currency, $receipt->received_at);
            $settings = Settings::query()->where('company_id', $company->id)->first();
            $allowance = BigDecimal::of((string) ($settings?->over_receipt_percent ?? '0'));
            $orderLines = OrderLine::query()->where('order_id', $order->id)->lockForUpdate()->get()->keyBy('id');
            $lines = ReceiptLine::query()->where('receipt_id', $receipt->id)->orderBy('line_no')->get();

            if ($lines->isEmpty()) {
                throw ProcurementError::make('document_empty', field: 'lines');
            }

            $receivedOn = $receipt->received_at->setTimezone($company->timezone ?: 'UTC')->toDateString();
            $items = Item::query()->whereKey($lines->pluck('item_id')->filter()->unique()->all())->get()->keyBy('id');
            $landed = LandedCostAllocation::query()->where('receipt_id', $receipt->id)
                ->whereIn('landed_cost_id', fn ($q) => $q->select('id')->from('procurement_landed_costs')->where('status', 'posted'))
                ->get()->groupBy('receipt_line_id');
            $amounts = ['stock_value' => 0, 'expense' => 0, 'grni' => 0, 'landed_cost' => 0];
            $stockLines = [];
            $value = 0;
            $baseValue = 0;
            $landedTotal = 0;

            foreach ($lines as $index => $line) {
                /** @var OrderLine $orderLine */
                $orderLine = $orderLines->get($line->order_line_id);
                $accepted = BigDecimal::of((string) $orderLine->received_qty)->minus((string) $orderLine->rejected_qty)->plus((string) $line->accepted_qty);
                $limit = BigDecimal::of((string) $orderLine->qty)->multipliedBy(BigDecimal::one()->plus($allowance->dividedBy(100, 6, RoundingMode::HalfUp)));

                if ($accepted->isGreaterThan($limit)) {
                    throw ProcurementError::make('over_receipt', ['line' => $orderLine->line_no], "lines.{$index}.received_qty");
                }

                $lineValue = (int) (string) BigDecimal::of($orderLine->net_minor)->multipliedBy((string) $line->accepted_qty)
                    ->dividedBy((string) $orderLine->qty, 0, RoundingMode::HalfUp);
                $lineBase = Pricing::convert($fx, $lineValue, $receipt->currency);
                $lineLanded = (int) ($landed->get($line->id)?->sum('base_amount_minor') ?? 0);
                $line->fill([
                    'value_minor' => $lineValue,
                    'base_value_minor' => $lineBase,
                    'landed_minor' => $lineLanded,
                    'on_time' => $order->expected_on === null ? null : $receivedOn <= $order->expected_on->toDateString(),
                ])->save();
                $orderLine->fill([
                    'received_qty' => (string) BigDecimal::of((string) $orderLine->received_qty)->plus((string) $line->received_qty),
                    'rejected_qty' => (string) BigDecimal::of((string) $orderLine->rejected_qty)->plus((string) $line->rejected_qty),
                ])->save();

                $stock = $line->item_id !== null && ($items->get($line->item_id)?->type === 'stock');
                $amounts[$stock ? 'stock_value' : 'expense'] += $lineBase + $lineLanded;
                $amounts['grni'] += $lineBase;
                $amounts['landed_cost'] += $lineLanded;
                $value += $lineValue;
                $baseValue += $lineBase;
                $landedTotal += $lineLanded;

                if ($stock && BigDecimal::of((string) $line->accepted_qty)->isPositive()) {
                    $stockLines[] = [
                        'line_id' => $line->id,
                        'item_id' => $line->item_id,
                        'uom_id' => $line->uom_id,
                        'qty' => self::decimal((string) $line->accepted_qty),
                        'cost_minor' => (string) ($lineBase + $lineLanded),
                        'currency' => $base,
                        'batch_number' => $line->batch_number,
                        'expires_on' => $line->expires_on?->toDateString(),
                        'serials' => array_values($line->serials ?? []),
                    ];
                }
            }

            $receipt->fill([
                'number' => $receipt->number ?? $this->numbers->next(DocumentNumbers::RECEIPT, $receipt->company_id, $receipt->branch_id, $receipt->location_id, $receipt->received_at),
                'status' => Receipt::POSTED,
                'fx' => $fx,
                'base_currency' => $base,
                'value_minor' => $value,
                'base_value_minor' => $baseValue,
                'landed_minor' => $landedTotal,
                'posted_by' => $by->id,
                'posted_at' => CarbonImmutable::now(),
            ])->save();
            $this->orders->received($order);

            $dimensions = ['branch_id' => $receipt->branch_id, 'party_id' => $receipt->party_id];

            foreach (['cost_centre_id', 'project_id'] as $dimension) {
                $values = $orderLines->only($lines->pluck('order_line_id')->all())->pluck($dimension)->unique();
                $dimensions[$dimension] = $values->count() === 1 ? $values->first() : null;
            }

            $tenant = (string) $receipt->tenant_id;

            return [
                $stockLines === [] || ! $this->modules->isActive('inventory') ? null : new GoodsReceived($tenant, $receipt->id, $receipt->company_id, $receipt->location_id,
                    $receipt->received_at->toIso8601String(), $receipt->number, $stockLines, $by->id),
                new GoodsReceiptPosted($tenant, $receipt->id, $receipt->company_id, $receipt->received_at->toIso8601String(), $base,
                    array_map(fn (int $amount) => (string) $amount, $amounts), $fx->jsonSerialize(), $dimensions, (string) $receipt->number, $order->id),
                $receipt,
            ];
        });

        [$goods, $posted, $posted_receipt] = $events;

        if ($goods !== null) {
            event($goods);
        }

        event($posted);

        return $posted_receipt->refresh();
    }

    public function cancel(Receipt $receipt, User $by, string $reason): Receipt
    {
        return Tx::run(function () use ($receipt, $by, $reason) {
            $receipt = Receipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if ($receipt->status !== Receipt::DRAFT) {
                throw ProcurementError::make('document_not_draft', status: 409);
            }

            if (LandedCostAllocation::query()->where('receipt_id', $receipt->id)
                ->whereIn('landed_cost_id', fn ($q) => $q->select('id')->from('procurement_landed_costs')->where('status', 'posted'))->exists()) {
                throw ProcurementError::make('receipt_has_landed_costs', status: 409);
            }

            $receipt->fill(['status' => Receipt::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            return $receipt->refresh();
        });
    }

    private function location(Order $order, string $locationId): Location
    {
        return Location::query()->whereKey($locationId)->whereNull('archived_at')
            ->whereIn('branch_id', fn ($q) => $q->select('id')->from('branches')->where('company_id', $order->company_id))->first()
            ?? throw ProcurementError::make('location_not_of_company', field: 'location_id');
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(Order $order, Receipt $receipt, array $lines): void
    {
        $orderLines = OrderLine::query()->where('order_id', $order->id)->get()->keyBy('id');
        $seen = [];

        foreach (array_values($lines) as $index => $input) {
            $orderLine = $orderLines->get($input['order_line_id'] ?? '') ?? throw ProcurementError::make('line_not_on_po', field: "lines.{$index}.order_line_id");

            if (isset($seen[$orderLine->id])) {
                throw ProcurementError::make('line_repeated', field: "lines.{$index}.order_line_id");
            }

            $seen[$orderLine->id] = true;
            $received = LineResolver::qty((string) $input['received_qty'], "lines.{$index}.received_qty");
            $rejected = isset($input['rejected_qty']) && (string) $input['rejected_qty'] !== '' && BigDecimal::of((string) $input['rejected_qty'])->isPositive()
                ? LineResolver::qty((string) $input['rejected_qty'], "lines.{$index}.rejected_qty") : '0';

            if (BigDecimal::of($rejected)->isGreaterThan($received)) {
                throw ProcurementError::make('rejected_exceeds_received', field: "lines.{$index}.rejected_qty");
            }

            ReceiptLine::query()->create([
                'receipt_id' => $receipt->id,
                'order_line_id' => $orderLine->id,
                'line_no' => $index + 1,
                'item_id' => $orderLine->item_id,
                'uom_id' => $orderLine->uom_id,
                'received_qty' => $received,
                'rejected_qty' => $rejected,
                'accepted_qty' => (string) BigDecimal::of($received)->minus($rejected),
                'reject_reason' => $input['reject_reason'] ?? null,
                'batch_number' => $input['batch_number'] ?? null,
                'expires_on' => $input['expires_on'] ?? null,
                'serials' => array_values(array_unique(array_map('strval', $input['serials'] ?? []))),
                'weight' => isset($input['weight']) && $input['weight'] !== '' ? (string) BigDecimal::of((string) $input['weight']) : null,
            ]);
        }
    }

    public static function decimal(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
