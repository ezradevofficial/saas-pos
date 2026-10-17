<?php

namespace App\Core\Webhooks\Jobs;

use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Webhooks\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * HOOK-01: one tenant's delivery log housekeeping (webhooks:sweep), in its
 * context: deliveries older than `webhooks.retention_days` are deleted
 * (an operational log, not a business record), and deliveries a dead
 * worker left pending, sending or overdue for a retry are queued again
 * (a `sending` one as a retry: it may have reached the receiver, which
 * deduplicates by event id).
 */
class SweepWebhooks implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 600;

    private const BATCH = 1000;

    public function __construct(
        public string $tenantId,
        public string $at,
    ) {
        $this->onQueue(config('webhooks.queue', 'webhooks'));
    }

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(): void
    {
        $at = CarbonImmutable::parse($this->at);
        $staleBefore = $at->subMinutes((int) config('webhooks.stuck_minutes', 15));

        // Pruned in batches, so no statement holds many rows or runs long.
        $before = $at->subDays((int) config('webhooks.retention_days', 30));

        do {
            $ids = WebhookDelivery::query()->where('created_at', '<', $before)->orderBy('created_at')->limit(self::BATCH)->pluck('id');
            WebhookDelivery::query()->whereKey($ids->all())->delete();
        } while ($ids->count() === self::BATCH);

        $stuck = WebhookDelivery::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('status', [WebhookDelivery::PENDING, WebhookDelivery::SENDING])->where('updated_at', '<', $staleBefore))
                ->orWhere(fn ($q) => $q->where('status', WebhookDelivery::RETRYING)->where('next_attempt_at', '<', $staleBefore)))
            ->limit(500)->get(['id', 'status']);

        foreach ($stuck as $delivery) {
            // Marked as queued again now, so the next sweep leaves it alone unless this job is lost too.
            WebhookDelivery::query()->whereKey($delivery->id)->where('status', $delivery->status)->update([
                'status' => $delivery->status === WebhookDelivery::SENDING ? WebhookDelivery::RETRYING : $delivery->status,
                'next_attempt_at' => $delivery->status === WebhookDelivery::PENDING ? null : $at,
                'updated_at' => $at,
            ]);

            SendWebhook::dispatch($this->tenantId, $delivery->id);
        }
    }
}
