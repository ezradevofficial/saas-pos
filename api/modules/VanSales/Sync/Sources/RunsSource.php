<?php

namespace Modules\VanSales\Sync\Sources;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\VanSales\Models\Count;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\Visit;
use Modules\VanSales\VanSalesServiceProvider;

/** VAN-06, VAN-09, VAN-18, NFR-04: the van's open or counting runs with their visits and the latest count's outcome (so a rep can resume and see a rejected count). */
class RunsSource implements SnapshotSource
{
    public function key(): string
    {
        return 'van_sales_runs';
    }

    public function module(): string
    {
        return VanSalesServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $van = VanSource::vanOf($scope);

        if ($van === null) {
            return [];
        }

        $runs = Run::query()->where('van_id', $van->id)->whereIn('status', ['open', 'counting'])->orderBy('started_at')->orderBy('id')->get();
        $visits = Visit::query()->whereIn('run_id', $runs->pluck('id'))->orderBy('device_updated_at')->orderBy('id')->get()->groupBy('run_id');
        $counts = Count::query()->whereIn('run_id', $runs->pluck('id'))->orderBy('attempt')->get()->groupBy('run_id');

        return $runs->map(function (Run $run) use ($visits, $counts) {
            $count = ($counts[$run->id] ?? collect())->last();

            return [
                'id' => $run->id,
                'route_id' => $run->route_id,
                'run_date' => $run->run_date->toDateString(),
                'status' => $run->status,
                'started_at' => $run->started_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'count' => $count === null ? null : ['id' => $count->id, 'status' => $count->status, 'attempt' => (int) $count->attempt],
                'visits' => ($visits[$run->id] ?? collect())->map(fn (Visit $visit) => [
                    'id' => $visit->id, 'stop_id' => $visit->stop_id, 'customer_id' => $visit->customer_id, 'status' => $visit->status,
                    'no_sale_reason' => $visit->no_sale_reason, 'note' => $visit->note,
                    'arrived_at' => $visit->arrived_at?->utc()->format('Y-m-d\TH:i:s.u\Z'), 'left_at' => $visit->left_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
                ])->values()->all(),
            ];
        })->values()->all();
    }
}
