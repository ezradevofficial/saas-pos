<?php

namespace Modules\VanSales\Reconciliation;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Contracts\StockTransfers;
use Modules\VanSales\Models\Count;
use Modules\VanSales\Models\Run;
use Throwable;

/**
 * VAN-10, VAN-17, VAN-20: a sale, collection, void or refund that reaches a
 * run after its count adds to that run's figures, so the count no longer
 * stands: it is turned down (a waiting flow is cancelled, an unload nobody
 * has taken yet is cancelled) and the run goes back to `counting`, for the
 * rep to count again. The new count is judged on all the day's figures; what
 * an earlier approval already wrote off or brought back is accounted for by
 * Counts. A run not counted yet, or already waiting for its recount, is left
 * as it is.
 */
class Reopening
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly StockTransfers $transfers,
        private readonly Auditor $auditor,
    ) {}

    public function late(Run $run, string $reason): void
    {
        $count = Count::query()->where('run_id', $run->id)->orderByDesc('attempt')->lockForUpdate()->first();

        if ($count === null || $count->status === 'rejected' || $run->status === 'open') {
            return;
        }

        $before = $count->status;

        if ($before === 'pending') {
            $workflow = $this->engine->current(ReconciliationType::KEY, $count->id);

            if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                $this->engine->cancel($workflow, null, $reason);
            }
        }

        // An unload nobody dispatched yet would carry the old count's figures: it is asked again with the new count.
        if ($count->unload_transfer_id !== null && $this->transfers->active()) {
            $status = $this->transfers->statuses([$count->unload_transfer_id])[$count->unload_transfer_id] ?? null;

            if (in_array($status, ['pending', 'approved'], true)) {
                try {
                    $this->transfers->cancel(User::query()->findOrFail($run->rep_id), $count->unload_transfer_id, $reason);
                    $count->unload_transfer_id = null;
                } catch (Throwable $e) {
                    Log::warning('van_sales: the old unload was not cancelled', ['count' => $count->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $count->forceFill([
            'status' => 'rejected', 'decided_at' => CarbonImmutable::now(), 'flags' => array_values(array_unique([...($count->flags ?? []), 'reopened'])),
        ])->save();
        $run->forceFill(['status' => 'counting', 'closed_at' => null])->save();
        $this->auditor->record('van_sales.count.reopen', $count, ['status' => $before], ['status' => 'rejected', 'reason' => $reason]);
    }
}
