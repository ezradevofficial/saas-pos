<?php

namespace Modules\Stores\Documents;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Modules\Stores\Events\StoreIssued;
use Modules\Stores\Models\Issue;
use Modules\Stores\Models\IssueLine;
use Modules\Stores\Models\Movement;
use Modules\Stores\Models\Requisition;
use Modules\Stores\Models\RequisitionLine;
use Modules\Stores\Stock\Errors;
use Modules\Stores\Stock\Lines;
use Modules\Stores\Stock\Places;
use Modules\Stores\Stock\Qty;
use Modules\Stores\Stock\StoreLedger;

/**
 * STR-04, STR-05, STR-07, STR-08: issues against a requisition.
 *
 * issue(), under the requisition's row lock: each line names a line of
 * the requisition and a positive quantity in that line's unit, within its
 * precision; what is issued over every issue never exceeds what was
 * approved (`over_issue`). The issue is numbered, the requisition lines
 * and status follow (`partially_issued`, `issued`), and the goods leave the
 * store in the ledger that holds its quantities now: the own ledger
 * refuses what the store does not hold (`insufficient_stock`); with
 * Inventory active the rows only record the issue and StoreIssued (after
 * commit) has Inventory move and value the stock.
 *
 * acknowledge(): the department confirms receipt; never by the issuer.
 */
class Issues
{
    public function __construct(
        private readonly Places $places,
        private readonly StoreLedger $ledger,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenants,
    ) {}

    /** @param array{note?: ?string, lines: list<array{requisition_line_id: string, qty: string}>} $data */
    public function issue(Requisition $requisition, array $data, User $by): Issue
    {
        return Errors::transaction(function () use ($requisition, $data, $by) {
            $requisition = Requisition::query()->whereKey($requisition->id)->lockForUpdate()->firstOrFail();

            if (! in_array($requisition->status, Requisition::ISSUABLE, true)) {
                throw Errors::make(409, 'document_not_open');
            }

            $this->places->store($requisition->location_id);
            $requested = $requisition->lines()->with('item')->get()->keyBy('id');
            $wanted = [];

            foreach (array_values($data['lines']) as $index => $line) {
                $at = "lines.{$index}";
                /** @var RequisitionLine|null $target */
                $target = $requested->get((string) $line['requisition_line_id']);

                if ($target === null) {
                    throw Errors::make(422, 'line_unknown', field: "{$at}.requisition_line_id");
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

                if (BigDecimal::of((string) $target->qty_issued)->plus($total)->isGreaterThan(BigDecimal::of((string) $target->qty))) {
                    throw Errors::make(422, 'over_issue', ['item' => $target->item->name, 'open' => Qty::show(BigDecimal::of((string) $target->qty)->minus((string) $target->qty_issued))], "{$at}.qty");
                }

                $wanted[$target->id] = ['line' => $target, 'qty' => $total];
            }

            $now = CarbonImmutable::now();
            $ledger = $this->ledger->current();

            if ($ledger === Movement::LEDGER_STORES) {
                StoreLedger::assertOpened($requisition->location_id);
            }
            $issue = Issue::create([
                'number' => $this->numbers->next(DocumentNumbers::ISSUE, $requisition->company_id, $requisition->branch_id, $requisition->location_id),
                'requisition_id' => $requisition->id,
                'company_id' => $requisition->company_id,
                'branch_id' => $requisition->branch_id,
                'location_id' => $requisition->location_id,
                'department_id' => $requisition->department_id,
                'cost_centre_id' => $requisition->cost_centre_id,
                'ledger' => $ledger,
                'status' => Issue::ISSUED,
                'note' => $data['note'] ?? null,
                'issued_by' => $by->id,
                'issued_at' => $now,
            ]);

            $movements = [];
            $event = [];
            $lineNo = 0;

            foreach ($wanted as $entry) {
                /** @var RequisitionLine $target */
                $target = $entry['line'];
                // The requisition line's own factor (fixed when it was asked), never today's unit factor.
                $base = Qty::baseOf($entry['qty'], (string) $target->qty, (string) $target->qty_base);
                $line = IssueLine::create([
                    'issue_id' => $issue->id,
                    'requisition_line_id' => $target->id,
                    'line_no' => ++$lineNo,
                    'item_id' => $target->item_id,
                    'uom_id' => $target->uom_id,
                    'qty' => (string) Qty::of($entry['qty']),
                    'qty_base' => (string) $base,
                ]);
                $target->update(['qty_issued' => (string) Qty::of(BigDecimal::of((string) $target->qty_issued)->plus($entry['qty']))]);

                $movements[] = ['item_id' => $target->item_id, 'location_id' => $requisition->location_id, 'kind' => Movement::ISSUE, 'qty' => $base->negated(),
                    'uom_id' => $target->uom_id, 'uom_qty' => $entry['qty'], 'source_line_id' => $line->id];
                $event[] = ['line_id' => $line->id, 'item_id' => $target->item_id, 'uom_id' => $target->uom_id, 'qty' => Qty::show($entry['qty']), 'qty_base' => Qty::show($base)];
            }

            $open = $requisition->lines()->whereColumn('qty_issued', '<', 'qty')->exists();
            $requisition->fill(['status' => $open ? Requisition::PARTIALLY_ISSUED : Requisition::ISSUED])->save();

            $this->ledger->post($ledger, $requisition->company_id, Movement::SOURCE_ISSUE, $issue->id, $now, $by->id, $movements, [
                'reference' => $issue->number, 'department_id' => $issue->department_id, 'cost_centre_id' => $issue->cost_centre_id,
            ]);

            StoreIssued::dispatch(
                $this->tenants->require(), $issue->id, $issue->number, $issue->company_id, $issue->branch_id, $issue->location_id,
                $issue->department_id, $issue->cost_centre_id, $ledger, $now->toIso8601String(), $by->id, $event,
            );

            return $issue->refresh();
        });
    }

    public function acknowledge(Issue $issue, User $by, ?string $note): Issue
    {
        return Errors::transaction(function () use ($issue, $by, $note) {
            $issue = Issue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();

            if ($issue->status !== Issue::ISSUED) {
                throw Errors::make(409, 'document_not_open');
            }

            if ($issue->issued_by === $by->id) {
                throw Errors::make(403, 'acknowledge_self');
            }

            $issue->fill(['status' => Issue::ACKNOWLEDGED, 'acknowledged_by' => $by->id, 'acknowledged_at' => CarbonImmutable::now(), 'acknowledge_note' => $note])->save();

            return $issue->refresh();
        });
    }
}
