<?php

namespace App\Core\Automation\Runtime;

use App\Core\Automation\Jobs\SendWebhookDelivery;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Models\WebhookDelivery;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * AUTO-05: work a dead worker left behind, in the current tenant. A run
 * `running` for more than `automation.stuck_minutes` is failed (error_code
 * stuck) and the administrators alerted; it is not retried, since some of
 * its actions may have committed. A webhook delivery `sending` that long
 * is tried again (receivers ignore repeats by X-Webhook-Id) or, out of
 * attempts, failed and alerted; one `pending` or `retrying` past its time
 * whose job was lost is queued again.
 */
class Reaper
{
    public function __construct(
        private readonly FailureAlert $alert,
        private readonly TenantContext $tenants,
    ) {}

    /** @return array{runs: int, deliveries: int} */
    public function reap(CarbonImmutable $at): array
    {
        $stale = $at->subMinutes((int) config('automation.stuck_minutes', 15));
        $runs = 0;
        $deliveries = 0;

        foreach (AutomationRun::query()->with('rule')->where('outcome', AutomationRun::RUNNING)->where('updated_at', '<', $stale)->get() as $run) {
            $updated = AutomationRun::query()->whereKey($run->id)->where('outcome', AutomationRun::RUNNING)->update([
                'outcome' => AutomationRun::FAILED,
                'error' => __('automation.errors.stuck'),
                'error_code' => 'stuck',
                'finished_at' => $at,
                'updated_at' => $at,
            ]);

            if ($updated === 1 && $run->rule !== null) {
                $this->alert->send($run->rule, $run->refresh());
                $runs++;
            }
        }

        $attempts = (int) config('automation.webhook_attempts', 3);

        foreach (WebhookDelivery::query()->with(['rule', 'run'])->where('status', WebhookDelivery::SENDING)->where('updated_at', '<', $stale)->get() as $delivery) {
            if ($delivery->attempts >= $attempts) {
                $delivery->fill(['status' => WebhookDelivery::FAILED, 'error' => __('automation.errors.stuck')])->save();

                if ($delivery->rule !== null && $delivery->run !== null) {
                    $this->alert->send($delivery->rule, $delivery->run, __('automation.errors.stuck'));
                }
            } else {
                $delivery->fill(['status' => WebhookDelivery::RETRYING])->save();
                SendWebhookDelivery::dispatch($this->tenants->require(), $delivery->id);
            }

            $deliveries++;
        }

        $lost = WebhookDelivery::query()
            ->where(fn ($q) => $q->where(fn ($p) => $p->where('status', WebhookDelivery::PENDING)->where('created_at', '<', $stale))
                ->orWhere(fn ($r) => $r->where('status', WebhookDelivery::RETRYING)->where('next_attempt_at', '<', $stale)))
            ->pluck('id');

        foreach ($lost as $id) {
            SendWebhookDelivery::dispatch($this->tenants->require(), $id);
            $deliveries++;
        }

        return ['runs' => $runs, 'deliveries' => $deliveries];
    }
}
