<?php

namespace App\Core\Observability\Http;

use App\Core\Observability\Metrics\HttpMetrics;
use App\Core\Observability\Metrics\MetricsStore;
use App\Core\Sync\SyncLag;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * NFR-10: `GET /metrics` in the Prometheus text format, for a scraper on
 * the private network. 404 (as if it did not exist) unless the caller
 * sends the bearer token `METRICS_TOKEN` or comes from an address in
 * `METRICS_ALLOW`; with neither configured, nobody can read it.
 *
 * Reports: request counts and latency histograms (RecordMetrics, kept in
 * Redis), queue sizes per Horizon queue, Horizon failed jobs and running
 * masters, database reachability and ping time, and device sync lag. No
 * tenant ids or names: labels are route patterns, queues and statuses.
 */
final class MetricsController
{
    public function __invoke(Request $request, Repository $config, MetricsStore $store): Response
    {
        abort_unless(self::allows($request, $config), 404);

        $lines = [];
        $help = [
            'http_requests_total' => ['counter', 'Requests by method, route pattern and status class.'],
            'http_request_duration_seconds' => ['histogram', 'Request duration by method and route pattern.'],
        ];

        $series = $store->all();
        ksort($series);
        foreach ($help as $name => [$type, $text]) {
            $lines[] = "# HELP {$name} {$text}";
            $lines[] = "# TYPE {$name} {$type}";
            foreach ($series as $key => $value) {
                if (str_starts_with($key, $name.'{') || str_starts_with($key, $name.'_')) {
                    $lines[] = $key.' '.HttpMetrics::number($value);
                }
            }
        }

        foreach ($this->gauges($config) as $name => [$text, $values]) {
            $lines[] = "# HELP {$name} {$text}";
            $lines[] = "# TYPE {$name} gauge";
            foreach ($values as $labels => $value) {
                $lines[] = $name.($labels === '' ? '' : '{'.$labels.'}').' '.HttpMetrics::number((float) $value);
            }
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function allows(Request $request, Repository $config): bool
    {
        $token = (string) $config->get('observability.metrics.token');
        if ($token !== '' && is_string($request->bearerToken()) && hash_equals($token, $request->bearerToken())) {
            return true;
        }

        $allow = (array) $config->get('observability.metrics.allow', []);

        return $allow !== [] && $request->ip() !== null && IpUtils::checkIp($request->ip(), $allow);
    }

    /** @return array<string, array{0: string, 1: array<string, float|int>}> */
    private function gauges(Repository $config): array
    {
        $gauges = [];

        $sizes = [];
        foreach ((array) $config->get('horizon.defaults', []) as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                $sizes['queue="'.HttpMetrics::escape((string) $queue).'"'] = $this->safely(fn () => Queue::connection($supervisor['connection'] ?? null)->size($queue));
            }
        }
        $gauges['app_queue_size'] = ['Jobs waiting per queue (-1: the queue could not be read).', $sizes];

        $gauges['app_horizon_failed_jobs'] = ['Failed jobs Horizon holds (recent: the last hour; total: the retention window).', [
            'window="recent"' => $this->safely(fn () => app(JobRepository::class)->countRecentlyFailed()),
            'window="total"' => $this->safely(fn () => app(JobRepository::class)->countFailed()),
        ]];
        $gauges['app_horizon_masters'] = ['Horizon master supervisors running.', [
            '' => $this->safely(fn () => count(app(MasterSupervisorRepository::class)->all())),
        ]];

        $started = microtime(true);
        $up = $this->safely(fn () => DB::connection($config->get('observability.health.database_connection', 'pgsql'))->selectOne('select 1 as ok') ? 1 : 0);
        $gauges['app_database_up'] = ['1 when the runtime database connection answers.', ['' => $up === 1 ? 1 : 0]];
        $gauges['app_database_ping_seconds'] = ['Time to run select 1.', ['' => microtime(true) - $started]];

        $lag = $this->safely(fn () => app(SyncLag::class)->check()['oldest_transaction_seconds'] ?? 0);
        $gauges['app_sync_lag_seconds'] = ['Age of the oldest running transaction that holds back device sync (NFR-04).', ['' => $lag]];

        return $gauges;
    }

    private function safely(callable $read): int|float
    {
        try {
            return $read();
        } catch (Throwable) {
            return -1;
        }
    }
}
