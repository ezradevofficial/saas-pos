<?php

namespace App\Core\MasterData\CreditLimits\Console;

use App\Core\MasterData\CreditLimits\CreditLimitChange;
use App\Core\MasterData\CreditLimits\CreditLimitChanges;
use App\Core\MasterData\CreditLimits\CreditLimitChangeType;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Models\DocumentWorkflowEvent;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * M4 (WF-10, WF-11): settle credit limit changes still pending whose flow
 * ended (completed or cancelled) more than a few minutes ago, which the
 * queued SettleCreditLimitChange listener missed (it failed for good, or
 * a worker died). Scheduled every five minutes on one server. Tenant ids
 * come from an owner-owned security-definer function on the runtime
 * connection (DueTenants, ADR 002); each tenant is settled in its own
 * context.
 */
class ReconcileCreditLimitChanges extends Command
{
    protected $signature = 'credit-limits:reconcile {--grace=5 : Minutes a flow has been over before it is settled here}';

    protected $description = 'Settle credit limit changes whose workflow ended but which are still pending';

    public function handle(TenantContext $tenants, CreditLimitChanges $changes, WorkflowEngine $engine, DueTenants $due): int
    {
        $before = CarbonImmutable::now()->subMinutes((int) $this->option('grace'));
        $settled = 0;

        foreach ($due->withUnsettledCreditChanges($before) as $tenantId) {
            $tenants->run($tenantId, function () use ($changes, $engine, $before, &$settled) {
                foreach (CreditLimitChange::query()->where('status', CreditLimitChange::PENDING)->pluck('id') as $id) {
                    try {
                        $workflow = $engine->current(CreditLimitChangeType::KEY, (string) $id);

                        if ($workflow?->status === DocumentWorkflow::COMPLETED && $workflow->completed_at?->lessThan($before)) {
                            $by = DocumentWorkflowEvent::query()->where('workflow_id', $workflow->id)->where('type', 'completed')->value('user_id');
                            $changes->completed((string) $id, (string) $workflow->outcome, $by);
                            $settled++;
                        } elseif ($workflow?->status === DocumentWorkflow::CANCELLED && $workflow->cancelled_at?->lessThan($before)) {
                            $changes->cancelled((string) $id);
                            $settled++;
                        }
                    } catch (Throwable $e) {
                        Log::warning('Credit limit change could not be reconciled', ['change_id' => $id, 'error' => $e->getMessage()]);
                    }
                }
            });
        }

        $this->components->info($settled.' credit limit changes settled.');

        return self::SUCCESS;
    }
}
