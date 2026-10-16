<?php

namespace Modules\Manufacturing\Orders;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Modules\Manufacturing\Events\MaterialsMoved;
use Modules\Manufacturing\Models\Issue;
use Modules\Manufacturing\Models\IssueLine;
use Modules\Manufacturing\Models\MaterialReturn;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Models\OrderMaterial;
use Modules\Manufacturing\Models\ReturnLine;
use Modules\Manufacturing\Support\Errors;
use Modules\Manufacturing\Support\Qty;
use Modules\Manufacturing\Support\Units;

/**
 * MFG-12: materials issued to a running order and returned from it, under
 * the order's row lock.
 *
 * - issue(): each line names a material of the order and a positive
 *   quantity in a unit of its item (default its base unit), within the
 *   unit's precision; beyond the requirement is allowed (a variance,
 *   decision 10). The order is `in_progress` from its first issue.
 * - return(): lines of one issue of the order, each at most what that line
 *   issued less what came back (`over_return`), in the issue line's unit.
 *
 * Both are numbered and follow the order's ledger: with Inventory's,
 * MaterialsMoved (after commit) has Inventory move and value the stock.
 */
class Materials
{
    public function __construct(
        private readonly Units $units,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenants,
    ) {}

    /** @param array{note?: ?string, lines: list<array{material_id: string, qty: string, uom_id?: ?string}>} $data */
    public function issue(Order $order, array $data, User $by): Issue
    {
        return Errors::transaction(function () use ($order, $data, $by) {
            $order = $this->running($order);
            $materials = OrderMaterial::query()->where('order_id', $order->id)->get()->keyBy('id');
            $items = Item::query()->whereKey($materials->pluck('item_id'))->get()->keyBy('id');
            $lines = [];

            foreach (array_values($data['lines']) as $index => $line) {
                $at = "lines.{$index}";
                $material = $materials->get((string) $line['material_id']) ?? throw Errors::make(422, 'line_unknown', field: "{$at}.material_id");
                $item = $items[$material->item_id];
                $uomId = (string) ($line['uom_id'] ?? $item->base_uom_id);
                $factor = $this->units->factor($item, $uomId) ?? throw Errors::make(422, 'uom_not_on_item', field: "{$at}.uom_id");
                $qty = $this->positive($line['qty'] ?? null, "{$at}.qty");

                if (! $this->units->fitsPrecision($uomId, $qty)) {
                    throw Errors::make(422, 'qty_precision', field: "{$at}.qty");
                }

                $lines[] = ['material' => $material, 'uom_id' => $uomId, 'qty' => Qty::of($qty), 'qty_base' => Qty::of($qty->multipliedBy($factor))];
            }

            $now = CarbonImmutable::now();
            $issue = Issue::create([
                'number' => $this->numbers->next(DocumentNumbers::ISSUE, $order->company_id, $order->branch_id, $order->location_id),
                'order_id' => $order->id, 'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'location_id' => $order->location_id,
                'ledger' => $order->ledger, 'note' => $data['note'] ?? null, 'issued_by' => $by->id, 'issued_at' => $now,
            ]);
            $event = [];

            foreach ($lines as $index => $line) {
                $material = $line['material'];
                $row = IssueLine::create([
                    'issue_id' => $issue->id, 'material_id' => $material->id, 'line_no' => $index + 1, 'item_id' => $material->item_id,
                    'uom_id' => $line['uom_id'], 'qty' => (string) $line['qty'], 'qty_base' => (string) $line['qty_base'],
                ]);
                $material->update(['qty_issued_base' => (string) Qty::of(BigDecimal::of((string) $material->qty_issued_base)->plus($line['qty_base']))]);
                $event[] = ['line_id' => $row->id, 'item_id' => $material->item_id, 'uom_id' => $line['uom_id'], 'qty' => Qty::show($line['qty']), 'qty_base' => Qty::show($line['qty_base'])];
            }

            $this->start($order);

            if ($order->isValued()) {
                MaterialsMoved::dispatch($this->tenants->require(), MaterialsMoved::ISSUE, $issue->id, $issue->number, $order->company_id, $order->branch_id,
                    $order->location_id, $now->toIso8601String(), $by->id, $event);
            }

            return $issue->refresh();
        });
    }

    /** @param array{issue_id: string, reason: string, note?: ?string, lines: list<array{issue_line_id: string, qty: string}>} $data */
    public function return(Order $order, array $data, User $by): MaterialReturn
    {
        return Errors::transaction(function () use ($order, $data, $by) {
            $order = $this->running($order);
            $issue = Issue::query()->where('order_id', $order->id)->whereKey((string) $data['issue_id'])->first()
                ?? throw Errors::make(422, 'line_unknown', field: 'issue_id');
            $issued = IssueLine::query()->where('issue_id', $issue->id)->lockForUpdate()->get()->keyBy('id');
            $items = Item::query()->whereKey($issued->pluck('item_id'))->get()->keyBy('id');
            $wanted = [];

            foreach (array_values($data['lines']) as $index => $line) {
                $at = "lines.{$index}";
                /** @var IssueLine|null $target */
                $target = $issued->get((string) $line['issue_line_id']) ?? throw Errors::make(422, 'line_unknown', field: "{$at}.issue_line_id");
                $qty = $this->positive($line['qty'] ?? null, "{$at}.qty");

                if (! $this->units->fitsPrecision($target->uom_id, $qty)) {
                    throw Errors::make(422, 'qty_precision', field: "{$at}.qty");
                }

                $base = Units::baseOf($qty, (string) $target->qty, (string) $target->qty_base);
                $total = ($wanted[$target->id]['qty_base'] ?? BigDecimal::zero())->plus($base);

                if (BigDecimal::of((string) $target->qty_returned_base)->plus($total)->isGreaterThan((string) $target->qty_base)) {
                    $open = BigDecimal::of((string) $target->qty_base)->minus((string) $target->qty_returned_base);
                    throw Errors::make(422, 'over_return', ['item' => $items[$target->item_id]->name, 'open' => Qty::show($open)], "{$at}.qty");
                }

                $wanted[$target->id] = ['line' => $target, 'qty' => ($wanted[$target->id]['qty'] ?? BigDecimal::zero())->plus($qty), 'qty_base' => $total];
            }

            $now = CarbonImmutable::now();
            $return = MaterialReturn::create([
                'number' => $this->numbers->next(DocumentNumbers::RETURN, $order->company_id, $order->branch_id, $order->location_id),
                'order_id' => $order->id, 'issue_id' => $issue->id, 'company_id' => $order->company_id, 'branch_id' => $order->branch_id,
                'location_id' => $order->location_id, 'ledger' => $issue->ledger, 'reason' => $data['reason'], 'note' => $data['note'] ?? null,
                'returned_by' => $by->id, 'returned_at' => $now,
            ]);
            $event = [];
            $lineNo = 0;

            foreach ($wanted as $entry) {
                /** @var IssueLine $target */
                $target = $entry['line'];
                $row = ReturnLine::create([
                    'return_id' => $return->id, 'issue_line_id' => $target->id, 'line_no' => ++$lineNo, 'item_id' => $target->item_id,
                    'uom_id' => $target->uom_id, 'qty' => (string) Qty::of($entry['qty']), 'qty_base' => (string) Qty::of($entry['qty_base']),
                ]);
                $target->update(['qty_returned_base' => (string) Qty::of(BigDecimal::of((string) $target->qty_returned_base)->plus($entry['qty_base']))]);
                $material = OrderMaterial::query()->whereKey($target->material_id)->lockForUpdate()->firstOrFail();
                $material->update(['qty_returned_base' => (string) Qty::of(BigDecimal::of((string) $material->qty_returned_base)->plus($entry['qty_base']))]);
                $event[] = ['line_id' => $row->id, 'item_id' => $target->item_id, 'uom_id' => $target->uom_id, 'qty' => Qty::show($entry['qty']),
                    'qty_base' => Qty::show($entry['qty_base']), 'origin_line_id' => $target->id];
            }

            if ($issue->ledger === Order::LEDGER_INVENTORY) {
                MaterialsMoved::dispatch($this->tenants->require(), MaterialsMoved::RETURN, $return->id, $return->number, $order->company_id, $order->branch_id,
                    $order->location_id, $now->toIso8601String(), $by->id, $event, $issue->id, $issue->valued_at !== null);
            }

            return $return->refresh();
        });
    }

    /** The order, locked, while it runs (409 otherwise). */
    public function running(Order $order): Order
    {
        $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

        if (! in_array($order->status, Order::RUNNING, true)) {
            throw Errors::make(409, 'document_not_open');
        }

        return $order;
    }

    /** The order is in progress from its first issue or report. */
    public function start(Order $order): void
    {
        if ($order->status === Order::RELEASED) {
            $order->fill(['status' => Order::IN_PROGRESS])->save();
        }
    }

    private function positive(mixed $value, string $field): BigDecimal
    {
        try {
            $qty = BigDecimal::of((string) $value);
        } catch (MathException) {
            throw Errors::make(422, 'qty_invalid', field: $field);
        }

        if (! $qty->isPositive()) {
            throw Errors::make(422, 'qty_invalid', field: $field);
        }

        return $qty;
    }
}
