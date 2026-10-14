<?php

namespace Modules\Stores\Documents;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Modules\Stores\Events\StoreReturned;
use Modules\Stores\Models\Issue;
use Modules\Stores\Models\IssueLine;
use Modules\Stores\Models\Movement;
use Modules\Stores\Models\ReturnLine;
use Modules\Stores\Models\StoreReturn;
use Modules\Stores\Stock\Errors;
use Modules\Stores\Stock\Lines;
use Modules\Stores\Stock\Places;
use Modules\Stores\Stock\Qty;
use Modules\Stores\Stock\StoreLedger;

/**
 * STR-06: returns to store, under the issue's row lock. Each line names a
 * line of the issue and a positive quantity in its unit; what comes back
 * never exceeds what was issued less what already came back
 * (`over_return`). The goods come back through the issue's own ledger
 * (`ledger_changed` when that ledger no longer holds the store's
 * quantities): the own ledger takes them in; with Inventory, StoreReturned
 * (after commit) has Inventory bring them back at the issue's cost.
 */
class Returns
{
    public function __construct(
        private readonly Places $places,
        private readonly StoreLedger $ledger,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenants,
    ) {}

    /** @param array{reason: string, note?: ?string, lines: list<array{issue_line_id: string, qty: string}>} $data */
    public function create(Issue $issue, array $data, User $by): StoreReturn
    {
        return Errors::transaction(function () use ($issue, $data, $by) {
            $issue = Issue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();

            if ($issue->ledger !== $this->ledger->current()) {
                throw Errors::make(409, 'ledger_changed');
            }

            $this->places->store($issue->location_id);
            $issued = $issue->lines()->with('item')->get()->keyBy('id');
            $wanted = [];

            foreach (array_values($data['lines']) as $index => $line) {
                $at = "lines.{$index}";
                /** @var IssueLine|null $target */
                $target = $issued->get((string) $line['issue_line_id']);

                if ($target === null) {
                    throw Errors::make(422, 'line_unknown', field: "{$at}.issue_line_id");
                }

                try {
                    $qty = BigDecimal::of((string) $line['qty']);
                } catch (MathException) {
                    throw Errors::make(422, 'qty_invalid', field: "{$at}.qty");
                }

                if (! $qty->isPositive()) {
                    throw Errors::make(422, 'qty_invalid', field: "{$at}.qty");
                }

                if (! Lines::fitsPrecision($target->uom_id, $qty)) {
                    throw Errors::make(422, 'qty_precision', field: "{$at}.qty");
                }

                $total = ($wanted[$target->id]['qty'] ?? BigDecimal::zero())->plus($qty);
                $base = Qty::of($total->multipliedBy(Lines::factor($target->item, $target->uom_id) ?? BigDecimal::one()));
                $left = BigDecimal::of((string) $target->qty_base)->minus((string) $target->qty_returned_base);

                if ($base->isGreaterThan($left)) {
                    throw Errors::make(422, 'over_return', ['item' => $target->item->name], "{$at}.qty");
                }

                $wanted[$target->id] = ['line' => $target, 'qty' => $total, 'base' => $base];
            }

            $now = CarbonImmutable::now();
            $return = StoreReturn::create([
                'number' => $this->numbers->next(DocumentNumbers::RETURN, $issue->company_id, $issue->branch_id, $issue->location_id),
                'issue_id' => $issue->id,
                'company_id' => $issue->company_id,
                'branch_id' => $issue->branch_id,
                'location_id' => $issue->location_id,
                'department_id' => $issue->department_id,
                'cost_centre_id' => $issue->cost_centre_id,
                'ledger' => $issue->ledger,
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
                'returned_by' => $by->id,
                'returned_at' => $now,
            ]);

            $movements = [];
            $event = [];
            $lineNo = 0;

            foreach ($wanted as $entry) {
                /** @var IssueLine $target */
                $target = $entry['line'];
                $line = ReturnLine::create([
                    'return_id' => $return->id,
                    'issue_line_id' => $target->id,
                    'line_no' => ++$lineNo,
                    'item_id' => $target->item_id,
                    'uom_id' => $target->uom_id,
                    'qty' => (string) Qty::of($entry['qty']),
                    'qty_base' => (string) $entry['base'],
                ]);
                $target->update(['qty_returned_base' => (string) Qty::of(BigDecimal::of((string) $target->qty_returned_base)->plus($entry['base']))]);

                $movements[] = ['item_id' => $target->item_id, 'location_id' => $issue->location_id, 'kind' => Movement::RETURN, 'qty' => $entry['base'],
                    'uom_id' => $target->uom_id, 'uom_qty' => $entry['qty'], 'source_line_id' => $line->id];
                $event[] = ['line_id' => $line->id, 'issue_line_id' => $target->id, 'item_id' => $target->item_id, 'uom_id' => $target->uom_id,
                    'qty' => Qty::show($entry['qty']), 'qty_base' => Qty::show($entry['base'])];
            }

            $this->ledger->post($issue->ledger, $issue->company_id, Movement::SOURCE_RETURN, $return->id, $now, $by->id, $movements, [
                'reference' => $return->number, 'department_id' => $issue->department_id, 'cost_centre_id' => $issue->cost_centre_id, 'reason' => $data['reason'],
            ]);

            StoreReturned::dispatch(
                $this->tenants->require(), $return->id, $return->number, $issue->id, $issue->company_id, $issue->branch_id, $issue->location_id,
                $issue->department_id, $issue->cost_centre_id, $issue->ledger, $now->toIso8601String(), $by->id, $event,
            );

            return $return->refresh();
        });
    }
}
