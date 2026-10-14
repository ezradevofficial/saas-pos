<?php

namespace Modules\Stores\Listeners;

use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Modules\Inventory\Events\StockMoved;
use Modules\Stores\Events\StoreConsumptionValued;
use Modules\Stores\Models\Issue;
use Modules\Stores\Models\IssueLine;
use Modules\Stores\Models\Movement;
use Modules\Stores\Models\ReturnLine;
use Modules\Stores\Models\StoreReturn;
use Modules\Stores\Stock\Errors;
use Modules\Stores\Stock\Qty;
use Modules\Stores\Stock\StoreLedger;

/**
 * STR-09: Inventory moved a Stores issue or return and says what it was
 * worth (StockMoved, source `stores.issue` or `stores.return`, a movement
 * per batch taken, each naming the Stores line). Under the document's row
 * lock, once: each line's value (the sum of its movements, positive), the
 * movements' signed values (written once), the document's currency and
 * time, then StoreConsumptionValued for Accounting. Recorded whatever the
 * module state now, so a document's value is never lost.
 */
class RecordStoreValues
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly StoreLedger $ledger,
    ) {}

    public function handle(StockMoved $event): void
    {
        if (! in_array($event->sourceType, [Movement::SOURCE_ISSUE, Movement::SOURCE_RETURN], true)) {
            return;
        }

        $this->tenants->run($event->tenantId, fn () => Errors::transaction(function () use ($event) {
            $issue = $event->sourceType === Movement::SOURCE_ISSUE;
            $document = ($issue ? Issue::query() : StoreReturn::query())->whereKey($event->sourceId)->lockForUpdate()->first();

            if ($document === null || $document->valued_at !== null) {
                return;
            }

            $values = [];

            foreach ($event->movements as $movement) {
                $line = $movement['source_line_id'] ?? null;

                if ($line !== null) {
                    $values[$line] = ($values[$line] ?? BigInteger::zero())->plus((string) $movement['value_minor']);
                }
            }

            $this->ledger->value($event->sourceType, $event->sourceId, array_map('strval', $values), $event->currency);
            $lines = ($issue ? IssueLine::query()->where('issue_id', $document->id) : ReturnLine::query()->where('return_id', $document->id))->orderBy('line_no')->get();
            $total = BigInteger::zero();
            $payload = [];

            foreach ($lines as $line) {
                if (! isset($values[$line->id])) {
                    continue;
                }

                $value = $values[$line->id]->abs();
                $line->update(['value_minor' => (string) $value]);
                $total = $total->plus($value);
                $payload[] = ['line_id' => $line->id, 'item_id' => $line->item_id, 'qty_base' => Qty::show($line->qty_base), 'value_minor' => (string) $value];
            }

            $document->fill(['valued_at' => CarbonImmutable::now(), 'currency' => $event->currency])->save();

            StoreConsumptionValued::dispatch(
                $event->tenantId,
                $issue ? StoreConsumptionValued::ISSUE : StoreConsumptionValued::RETURN,
                $document->id, $document->number, $document->company_id, $document->branch_id, $document->location_id,
                $document->department_id, $document->cost_centre_id,
                ($issue ? $document->issued_at : $document->returned_at)->toIso8601String(),
                $event->currency, (string) $total, $payload,
            );
        }));
    }
}
