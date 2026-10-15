<?php

namespace Modules\Stores\Events;

use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Modules\Stores\Models\Issue;
use Modules\Stores\Models\IssueLine;
use Modules\Stores\Models\ReturnLine;
use Modules\Stores\Models\StoreReturn;
use Modules\Stores\Stock\Qty;

/**
 * STR-09, STR-16: StoreConsumptionValued of a valued issue or return, from
 * what Stores stored (each line's value and cost variance), raised live by
 * RecordStoreValues and again for a period by the Accounting backfill
 * (core EventReplays, ACC-29), in the order the documents happened.
 */
final class ConsumptionEvents
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function of(Issue|StoreReturn $document): StoreConsumptionValued
    {
        $issue = $document instanceof Issue;
        $lines = ($issue ? IssueLine::query()->where('issue_id', $document->id) : ReturnLine::query()->where('return_id', $document->id))
            ->whereNotNull('value_minor')->orderBy('line_no')->get();
        $total = 0;
        $payload = [];

        foreach ($lines as $line) {
            $value = abs((int) $line->value_minor);
            $total += $value;
            $payload[] = ['line_id' => $line->id, 'item_id' => $line->item_id, 'qty_base' => Qty::show($line->qty_base), 'value_minor' => (string) $value,
                'cost_variance_minor' => (string) ($line->cost_variance_minor ?? 0)];
        }

        return new StoreConsumptionValued(
            $this->tenants->require(),
            $issue ? StoreConsumptionValued::ISSUE : StoreConsumptionValued::RETURN,
            $document->id, (string) $document->number, $document->company_id, $document->branch_id, $document->location_id,
            $document->department_id, $document->cost_centre_id,
            ($issue ? $document->issued_at : $document->returned_at)->toIso8601String(),
            (string) $document->currency, (string) $total, $payload,
        );
    }

    /** @param Closure(object): void $emit */
    public function replay(CarbonImmutable $from, CarbonImmutable $to, Closure $emit): void
    {
        $documents = [
            ...Issue::query()->whereNotNull('valued_at')->whereBetween('issued_at', [$from, $to])->get()->all(),
            ...StoreReturn::query()->whereNotNull('valued_at')->whereBetween('returned_at', [$from, $to])->get()->all(),
        ];
        usort($documents, fn ($a, $b) => [($a instanceof Issue ? $a->issued_at : $a->returned_at), $a->id] <=> [($b instanceof Issue ? $b->issued_at : $b->returned_at), $b->id]);

        foreach ($documents as $document) {
            $emit($this->of($document));
        }
    }
}
