<?php

namespace Modules\Salon\Sync\Sources;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\Salon\Models\QueueEntry;
use Modules\Salon\Queue\Queue;
use Modules\Salon\SalonServiceProvider;

/** SAL-12, SAL-29: the walk-ins waiting at the till's branch (a name and what they want; no contact details). */
class QueueSource implements SnapshotSource
{
    public function __construct(private readonly Queue $queue) {}

    public function key(): string
    {
        return 'salon_queue';
    }

    public function module(): string
    {
        return SalonServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $waits = $this->queue->waits($scope->branch->id);

        return QueueEntry::query()->where('branch_id', $scope->branch->id)->where('status', QueueEntry::WAITING)->orderBy('joined_at')->orderBy('id')->get()
            ->values()->map(fn (QueueEntry $e, int $index) => [
                'id' => $e->id, 'guest_name' => $e->guest_name, 'client_id' => $e->client_id, 'service_ids' => $e->service_ids, 'staff_id' => $e->staff_id,
                'joined_at' => $e->joined_at->toIso8601String(), 'position' => $index + 1, 'wait_minutes' => $waits[$e->id] ?? null,
            ])->all();
    }
}
