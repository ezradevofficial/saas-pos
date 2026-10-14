<?php

namespace Modules\Stores\Listeners;

use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Inventory\Events\StockMoved;
use Modules\Stores\Events\ConsumptionEvents;
use Modules\Stores\Models\Issue;
use Modules\Stores\Models\IssueLine;
use Modules\Stores\Models\Movement;
use Modules\Stores\Models\ReturnLine;
use Modules\Stores\Models\StoreReturn;
use Modules\Stores\Stock\Errors;
use Modules\Stores\Stock\StoreLedger;

/**
 * STR-09: Inventory moved a Stores issue or return and says what it was
 * worth (StockMoved, source `stores.issue` or `stores.return`, a movement
 * per batch taken, each naming the Stores line). Under the document's row
 * lock, once: each line's value (the sum of its movements, positive) and
 * cost variance, the movements' signed values (written once), the
 * document's currency and time, then StoreConsumptionValued for
 * Accounting. Queued after Inventory's posting commits and retried; a
 * redelivered Inventory job raises StockMoved again (MoveStockForStores),
 * so a value missed once is recorded later, and the `valued_at` guard keeps
 * it once. Recorded whatever the module state now.
 */
class RecordStoreValues implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly StoreLedger $ledger,
        private readonly ConsumptionEvents $events,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    /** Only Stores' own sources are queued. */
    public function shouldQueue(StockMoved $event): bool
    {
        return in_array($event->sourceType, [Movement::SOURCE_ISSUE, Movement::SOURCE_RETURN], true);
    }

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
            $variances = [];

            foreach ($event->movements as $movement) {
                $line = $movement['source_line_id'] ?? null;

                if ($line !== null) {
                    $values[$line] = ($values[$line] ?? BigInteger::zero())->plus((string) $movement['value_minor']);
                    $variances[$line] = ($variances[$line] ?? BigInteger::zero())->plus((string) ($movement['cost_variance_minor'] ?? '0'));
                }
            }

            $this->ledger->value($event->sourceType, $event->sourceId, array_map('strval', $values), $event->currency);
            $lines = ($issue ? IssueLine::query()->where('issue_id', $document->id) : ReturnLine::query()->where('return_id', $document->id))->orderBy('line_no')->get();

            foreach ($lines as $line) {
                if (isset($values[$line->id])) {
                    // ACC-29: the cost variance is kept too, so the backfill raises the same event again.
                    $line->update(['value_minor' => (string) $values[$line->id]->abs(), 'cost_variance_minor' => (string) $variances[$line->id]]);
                }
            }

            $document->fill(['valued_at' => CarbonImmutable::now(), 'currency' => $event->currency])->save();

            event($this->events->of($document));
        }));
    }
}
