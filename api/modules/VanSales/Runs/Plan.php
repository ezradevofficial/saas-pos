<?php

namespace Modules\VanSales\Runs;

use App\Core\MasterData\Parties\Party;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\VanSales\Models\Route;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\Stop;
use Modules\VanSales\Models\Visit;

/**
 * VAN-05: the route plan for a day. A stop is due when the weekday (ISO 1 to
 * 7) is in its own visit days, else in its route's. The status of a due stop
 * is that of the latest visit to the customer on that date's runs of the
 * route: `pending` until there is one.
 */
final class Plan
{
    /** @param list<int>|null $stopDays @param list<int> $routeDays */
    public static function due(?array $stopDays, array $routeDays, int $weekday): bool
    {
        return in_array($weekday, $stopDays ?? $routeDays, true);
    }

    /**
     * @param  Collection<int, Route>  $routes
     * @return list<array{route_id: string, name: string, stops: list<array<string, mixed>>}>
     */
    public function forDate(Collection $routes, string $date): array
    {
        $weekday = CarbonImmutable::parse($date)->dayOfWeekIso;
        $stops = Stop::query()->whereNull('archived_at')->whereIn('route_id', $routes->pluck('id'))->orderBy('position')->orderBy('id')->get()->groupBy('route_id');
        $customers = Party::query()->whereKey($stops->flatten()->pluck('customer_id')->unique()->all())->pluck('name', 'id');
        $runs = Run::query()->where('run_date', $date)->whereIn('route_id', $routes->pluck('id'))->get();
        $visits = Visit::query()->whereIn('run_id', $runs->pluck('id'))->orderBy('device_updated_at')->get();
        $runRoute = $runs->pluck('route_id', 'id');
        $latest = [];

        foreach ($visits as $visit) {
            $latest[$runRoute[$visit->run_id].'|'.$visit->customer_id] = $visit;
        }

        $plan = [];

        foreach ($routes as $route) {
            $due = [];

            foreach ($stops[$route->id] ?? [] as $stop) {
                if (! self::due($stop->visit_days, array_values($route->visit_days ?? []), $weekday)) {
                    continue;
                }

                $visit = $latest[$route->id.'|'.$stop->customer_id] ?? null;
                $due[] = [
                    'stop_id' => $stop->id, 'customer_id' => $stop->customer_id, 'customer_name' => $customers[$stop->customer_id] ?? null,
                    'position' => (int) $stop->position, 'status' => $visit?->status ?? 'pending', 'visit_id' => $visit?->id,
                ];
            }

            if ($due !== []) {
                $plan[] = ['route_id' => $route->id, 'name' => $route->name, 'stops' => $due];
            }
        }

        return $plan;
    }
}
