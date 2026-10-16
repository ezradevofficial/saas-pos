<?php

namespace Modules\Manufacturing\Events;

use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Closure;
use Modules\Manufacturing\Models\Backflush;
use Modules\Manufacturing\Models\BackflushLine;
use Modules\Manufacturing\Models\Issue;
use Modules\Manufacturing\Models\IssueLine;
use Modules\Manufacturing\Models\MaterialReturn;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Models\Report;
use Modules\Manufacturing\Models\ReportLine;
use Modules\Manufacturing\Models\ReturnLine;

/**
 * MFG-20: ProductionValued of a valued document, from what Manufacturing
 * stored (each line's value, positive, and Inventory's cost variance),
 * signed as a stock movement (out negative). Raised live once per document
 * and again for a period by the Accounting backfill (core EventReplays,
 * ACC-29), in the order the documents happened.
 */
final class ValuedEvents
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function materials(Issue|MaterialReturn $document): ProductionValued
    {
        $issue = $document instanceof Issue;
        $lines = ($issue ? IssueLine::query()->where('issue_id', $document->id) : ReturnLine::query()->where('return_id', $document->id))
            ->whereNotNull('value_minor')->orderBy('line_no')->get();
        $order = Order::query()->findOrFail($document->order_id);

        return new ProductionValued(
            $this->tenants->require(), ProductionValued::MATERIALS, $document->id, (string) $document->number, $order->id, $order->number,
            $document->company_id, $document->branch_id, $document->location_id,
            ($issue ? $document->issued_at : $document->returned_at)->toIso8601String(), (string) $document->currency,
            $lines->map(fn ($line) => self::line($line->id, $line->item_id, $line->value_minor, $line->cost_variance_minor, $issue))->values()->all(),
            documentKind: $issue ? 'manufacturing.issue' : 'manufacturing.return',
        );
    }

    public function production(Report $report): ProductionValued
    {
        $order = Order::query()->findOrFail($report->order_id);
        $lines = ReportLine::query()->where('report_id', $report->id)->whereIn('kind', [ReportLine::PRODUCT, ReportLine::BY_PRODUCT])->orderBy('line_no')->get();

        return new ProductionValued(
            $this->tenants->require(), ProductionValued::PRODUCTION, $report->id, $report->number, $order->id, $order->number,
            $report->company_id, $report->branch_id, $report->location_id, $report->reported_at->toIso8601String(), (string) ($report->currency ?? $order->currency),
            $lines->map(fn (ReportLine $line) => self::line($line->id, $line->item_id, $line->cost_minor ?? 0, $line->cost_variance_minor, false))->values()->all(),
            (string) ($report->labour_cost_minor ?? 0), (string) ($report->overhead_cost_minor ?? 0),
            documentKind: 'manufacturing.report',
        );
    }

    public function variance(Order $order): ProductionValued
    {
        return new ProductionValued(
            $this->tenants->require(), ProductionValued::VARIANCE, $order->id, $order->number, $order->id, $order->number,
            $order->company_id, $order->branch_id, $order->location_id, $order->closed_at->toIso8601String(), (string) $order->currency,
            [], varianceMinor: (string) ($order->variance_minor ?? 0), documentKind: 'manufacturing.order',
        );
    }

    public function backflush(Backflush $backflush): ProductionValued
    {
        $out = $backflush->kind === Backflush::CONSUME;
        $lines = BackflushLine::query()->where('backflush_id', $backflush->id)->whereNotNull('value_minor')->orderBy('line_no')->get();

        return new ProductionValued(
            $this->tenants->require(), ProductionValued::BACKFLUSH, $backflush->id, (string) ($backflush->reference ?? ''), null, null,
            $backflush->company_id, $backflush->branch_id, $backflush->location_id, $backflush->occurred_at->toIso8601String(), (string) $backflush->currency,
            $lines->map(fn (BackflushLine $line) => self::line($line->id, $line->item_id, $line->value_minor, $line->cost_variance_minor, $out))->values()->all(),
            documentKind: 'manufacturing.backflush',
        );
    }

    /** @param Closure(object): void $emit */
    public function replay(CarbonImmutable $from, CarbonImmutable $to, Closure $emit): void
    {
        $events = [];

        foreach (Issue::query()->whereNotNull('valued_at')->whereBetween('issued_at', [$from, $to])->get() as $issue) {
            $events[] = [$issue->issued_at, $issue->id, fn () => $this->materials($issue)];
        }

        foreach (MaterialReturn::query()->whereNotNull('valued_at')->whereBetween('returned_at', [$from, $to])->get() as $return) {
            $events[] = [$return->returned_at, $return->id, fn () => $this->materials($return)];
        }

        foreach (Report::query()->where('status', Report::RECEIVED)->whereBetween('reported_at', [$from, $to])->get() as $report) {
            $events[] = [$report->reported_at, $report->id, fn () => $this->production($report)];
        }

        foreach (Order::query()->where('status', Order::CLOSED)->whereNotNull('variance_minor')->where('variance_minor', '<>', 0)->whereBetween('closed_at', [$from, $to])->get() as $order) {
            $events[] = [$order->closed_at, $order->id, fn () => $this->variance($order)];
        }

        foreach (Backflush::query()->whereNotNull('valued_at')->whereBetween('occurred_at', [$from, $to])->get() as $backflush) {
            $events[] = [$backflush->occurred_at, $backflush->id, fn () => $this->backflush($backflush)];
        }

        usort($events, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        foreach ($events as [, , $make]) {
            $emit($make());
        }
    }

    /** @return array{line_id: string, item_id: string, value_minor: string, cost_variance_minor: string} */
    private static function line(string $id, string $itemId, int|string|null $value, int|string|null $variance, bool $out): array
    {
        $minor = BigInteger::of((string) ($value ?? 0))->abs();

        return [
            'line_id' => $id, 'item_id' => $itemId,
            'value_minor' => (string) ($out ? $minor->negated() : $minor),
            'cost_variance_minor' => (string) ($variance ?? 0),
        ];
    }
}
