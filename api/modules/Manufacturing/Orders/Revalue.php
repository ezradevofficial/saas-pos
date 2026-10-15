<?php

namespace Modules\Manufacturing\Orders;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Modules\Manufacturing\Events\MaterialsMoved;
use Modules\Manufacturing\Models\Backflush;
use Modules\Manufacturing\Models\BackflushLine;
use Modules\Manufacturing\Models\Issue;
use Modules\Manufacturing\Models\IssueLine;
use Modules\Manufacturing\Models\MaterialReturn;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Models\Report;
use Modules\Manufacturing\Models\ReturnLine;
use Modules\Manufacturing\Support\Errors;
use Modules\Manufacturing\Support\Qty;

/**
 * MFG-15, MFG-16, MFG-20 (review fix 5): an administrator has a document
 * whose value never came back from Inventory (its job gave up) moved and
 * valued again: MaterialsMoved is raised once more from its stored lines.
 * Inventory posts each source once and raises StockMoved again for one it
 * already posted, and RecordValues values a document once, so this never
 * counts anything twice. A document already valued (a report received)
 * is refused (409 `nothing_to_revalue`). Audited.
 */
class Revalue
{
    public const KINDS = ['issue', 'return', 'report', 'backflush'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Costing $costing,
        private readonly Auditor $auditor,
    ) {}

    /** The document of $kind with $id, under RLS, or null. */
    public static function find(string $kind, string $id): ?Model
    {
        return match ($kind) {
            'issue' => Issue::query()->find($id),
            'return' => MaterialReturn::query()->find($id),
            'report' => Report::query()->find($id),
            'backflush' => Backflush::query()->find($id),
            default => null,
        };
    }

    public function run(string $kind, Model $document, User $by): void
    {
        Errors::transaction(function () use ($kind, $document, $by) {
            $document = $document->newQuery()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            match ($kind) {
                'issue' => $this->issue($document),
                'return' => $this->return($document),
                'report' => $this->report($document),
                default => $this->backflush($document),
            };

            $this->auditor->record('manufacturing.document.revalue', $document, null, ['kind' => $kind, 'by' => $by->id]);
        });
    }

    private function issue(Issue $issue): void
    {
        $this->stuck($issue->valued_at === null && $issue->ledger === Order::LEDGER_INVENTORY);
        $lines = IssueLine::query()->where('issue_id', $issue->id)->orderBy('line_no')->get()->map(fn (IssueLine $line) => [
            'line_id' => $line->id, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'qty' => Qty::show($line->qty), 'qty_base' => Qty::show($line->qty_base),
        ])->all();

        MaterialsMoved::dispatch($this->tenants->require(), MaterialsMoved::ISSUE, $issue->id, $issue->number, $issue->company_id, $issue->branch_id,
            $issue->location_id, $issue->issued_at->toIso8601String(), $issue->issued_by, $lines);
    }

    private function return(MaterialReturn $return): void
    {
        $this->stuck($return->valued_at === null && $return->ledger === Order::LEDGER_INVENTORY);
        $issue = Issue::query()->findOrFail($return->issue_id);
        $lines = ReturnLine::query()->where('return_id', $return->id)->orderBy('line_no')->get()->map(fn (ReturnLine $line) => [
            'line_id' => $line->id, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'qty' => Qty::show($line->qty), 'qty_base' => Qty::show($line->qty_base),
            'origin_line_id' => $line->issue_line_id,
        ])->all();

        MaterialsMoved::dispatch($this->tenants->require(), MaterialsMoved::RETURN, $return->id, $return->number, $return->company_id, $return->branch_id,
            $return->location_id, $return->returned_at->toIso8601String(), $return->returned_by, $lines, $issue->id, $issue->valued_at !== null);
    }

    private function report(Report $report): void
    {
        $this->stuck(in_array($report->status, [Report::PENDING, Report::COSTED], true));
        $order = Order::query()->whereKey($report->order_id)->lockForUpdate()->firstOrFail();

        if ($report->status === Report::PENDING) {
            // Costed once its materials are valued; its receipt follows.
            $this->costing->costPending($order->id);

            return;
        }

        $this->costing->receive($order, $report);
    }

    private function backflush(Backflush $backflush): void
    {
        $this->stuck($backflush->valued_at === null);
        $consume = $backflush->kind === Backflush::CONSUME;
        $origin = $consume ? null : Backflush::query()->where('source_type', Backflush::SALE)->where('sale_id', $backflush->sale_id)->first();
        $lines = BackflushLine::query()->where('backflush_id', $backflush->id)->orderBy('line_no')->get()->map(fn (BackflushLine $line) => [
            'line_id' => $line->id, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'qty' => Qty::show($line->qty_base), 'qty_base' => Qty::show($line->qty_base),
            ...($consume ? [] : ['origin_line_id' => $line->origin_line_id]),
        ])->all();

        MaterialsMoved::dispatch($this->tenants->require(), $consume ? MaterialsMoved::BACKFLUSH : MaterialsMoved::BACKFLUSH_RETURN, $backflush->id, $backflush->reference,
            $backflush->company_id, $backflush->branch_id, $backflush->location_id, $backflush->occurred_at->toIso8601String(), null, $lines,
            $origin?->id, $origin?->valued_at !== null);
    }

    private function stuck(bool $stuck): void
    {
        if (! $stuck) {
            throw Errors::make(409, 'nothing_to_revalue');
        }
    }
}
