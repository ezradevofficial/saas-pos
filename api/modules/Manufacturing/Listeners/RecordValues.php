<?php

namespace Modules\Manufacturing\Listeners;

use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Inventory\Events\StockMoved;
use Modules\Manufacturing\Events\MaterialsMoved;
use Modules\Manufacturing\Events\ValuedEvents;
use Modules\Manufacturing\Models\Backflush;
use Modules\Manufacturing\Models\BackflushLine;
use Modules\Manufacturing\Models\Issue;
use Modules\Manufacturing\Models\IssueLine;
use Modules\Manufacturing\Models\MaterialReturn;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Models\OrderMaterial;
use Modules\Manufacturing\Models\Report;
use Modules\Manufacturing\Models\ReportLine;
use Modules\Manufacturing\Models\ReturnLine;
use Modules\Manufacturing\Orders\Costing;
use Modules\Manufacturing\Support\Errors;

/**
 * MFG-15, MFG-16, MFG-20: Inventory moved a Manufacturing document and
 * says what it was worth (StockMoved, sources `manufacturing.*`, a
 * movement per batch taken, each naming the Manufacturing line). Once per
 * document (`valued_at`, `received_at`), under the order's then the
 * document's row lock:
 *
 * - an issue or return: each line's value (the sum of its movements,
 *   positive) and cost variance; the materials' and order's values; then
 *   the order's pending reports are costed (Costing);
 * - a report's receipt: each line's cost variance; the report received;
 * - a backflush: each ingredient line's value and cost variance.
 *
 * Then ProductionValued for Accounting. Queued after Inventory's posting
 * commits and retried; a redelivered Inventory job raises StockMoved
 * again, so a value missed once is recorded later. Recorded whatever the
 * module state now (the document was made while it was active).
 */
class RecordValues implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Costing $costing,
        private readonly ValuedEvents $events,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    /** Only Manufacturing's own sources are queued. */
    public function shouldQueue(StockMoved $event): bool
    {
        return in_array($event->sourceType, MaterialsMoved::SOURCES, true);
    }

    public function handle(StockMoved $event): void
    {
        if (! $this->shouldQueue($event)) {
            return;
        }

        [$values, $variances] = $this->perLine($event);

        $this->tenants->run($event->tenantId, fn () => Errors::transaction(fn () => match ($event->sourceType) {
            MaterialsMoved::SOURCES[MaterialsMoved::ISSUE], MaterialsMoved::SOURCES[MaterialsMoved::RETURN] => $this->materials($event, $values, $variances),
            MaterialsMoved::SOURCES[MaterialsMoved::RECEIPT] => $this->receipt($event, $variances),
            default => $this->backflush($event, $values, $variances),
        }));
    }

    /** @return array{0: array<string, BigInteger>, 1: array<string, BigInteger>} each line's value and cost variance (signed, summed over its movements) */
    private function perLine(StockMoved $event): array
    {
        $values = [];
        $variances = [];

        foreach ($event->movements as $movement) {
            $line = $movement['source_line_id'] ?? null;

            if ($line !== null) {
                $values[$line] = ($values[$line] ?? BigInteger::zero())->plus((string) $movement['value_minor']);
                $variances[$line] = ($variances[$line] ?? BigInteger::zero())->plus((string) ($movement['cost_variance_minor'] ?? '0'));
            }
        }

        return [$values, $variances];
    }

    private function materials(StockMoved $event, array $values, array $variances): void
    {
        $issue = $event->sourceType === MaterialsMoved::SOURCES[MaterialsMoved::ISSUE];
        $orderId = ($issue ? Issue::query() : MaterialReturn::query())->whereKey($event->sourceId)->value('order_id');

        if ($orderId === null) {
            return;
        }

        $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
        $document = ($issue ? Issue::query() : MaterialReturn::query())->whereKey($event->sourceId)->lockForUpdate()->firstOrFail();

        if ($document->valued_at !== null) {
            return;
        }

        $lines = ($issue ? IssueLine::query()->where('issue_id', $document->id) : ReturnLine::query()->where('return_id', $document->id))->orderBy('line_no')->get();
        $total = BigInteger::zero();

        foreach ($lines as $line) {
            $value = ($values[$line->id] ?? BigInteger::zero())->abs();
            $line->update(['value_minor' => (string) $value, 'cost_variance_minor' => (string) ($variances[$line->id] ?? BigInteger::zero())]);
            $total = $total->plus($value);
            $materialId = $issue ? $line->material_id : IssueLine::query()->whereKey($line->issue_line_id)->value('material_id');
            $material = OrderMaterial::query()->whereKey($materialId)->lockForUpdate()->firstOrFail();
            $column = $issue ? 'value_issued_minor' : 'value_returned_minor';
            $material->update([$column => (string) BigInteger::of((string) $material->{$column})->plus($value)]);
        }

        $document->fill(['valued_at' => CarbonImmutable::now(), 'currency' => $event->currency])->save();
        $cost = BigInteger::of((string) $order->material_cost_minor);
        $order->fill(['material_cost_minor' => (string) ($issue ? $cost->plus($total) : $cost->minus($total))])->save();

        event($this->events->materials($document->refresh()));
        $this->costing->costPending($order->id);
    }

    private function receipt(StockMoved $event, array $variances): void
    {
        $orderId = Report::query()->whereKey($event->sourceId)->value('order_id');

        if ($orderId === null) {
            return;
        }

        Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
        $report = Report::query()->whereKey($event->sourceId)->lockForUpdate()->firstOrFail();

        if ($report->status !== Report::COSTED) {
            return;
        }

        foreach (ReportLine::query()->where('report_id', $report->id)->get() as $line) {
            if (isset($variances[$line->id])) {
                $line->update(['cost_variance_minor' => (string) $variances[$line->id]]);
            }
        }

        $report->fill(['status' => Report::RECEIVED, 'received_at' => CarbonImmutable::now()])->save();

        event($this->events->production($report->refresh()));
    }

    private function backflush(StockMoved $event, array $values, array $variances): void
    {
        $backflush = Backflush::query()->whereKey($event->sourceId)->lockForUpdate()->first();

        if ($backflush === null || $backflush->valued_at !== null) {
            return;
        }

        foreach (BackflushLine::query()->where('backflush_id', $backflush->id)->get() as $line) {
            $line->update([
                'value_minor' => (string) ($values[$line->id] ?? BigInteger::zero())->abs(),
                'cost_variance_minor' => (string) ($variances[$line->id] ?? BigInteger::zero()),
            ]);
        }

        $backflush->fill(['valued_at' => CarbonImmutable::now(), 'currency' => $event->currency])->save();

        event($this->events->backflush($backflush->refresh()));
    }
}
