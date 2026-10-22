<?php

namespace App\Core\Observability\Health;

use App\Core\Files\TenantFiles;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use RuntimeException;
use Throwable;

/**
 * NFR-01: can this API host serve? Database (the runtime role), Redis,
 * the queue connection and the file storage disk, each timed. Horizon is
 * reported too, and counts towards readiness only when
 * `observability.health.require_horizon` is set (workers usually run on
 * another host, and a stopped worker must not take the API out of the
 * load balancer).
 *
 * Failure details go to the (scrubbed) log, never to the response: the
 * endpoint is public to the load balancer and uptime monitor.
 */
class Readiness
{
    public function __construct(private readonly Repository $config) {}

    /** @return array{ready: bool, checks: array<string, array{status: string, ms: int|float}>} */
    public function run(): array
    {
        $checks = [
            'database' => fn () => DB::connection($this->config->get('observability.health.database_connection', 'pgsql'))->selectOne('select 1 as ok'),
            'redis' => fn () => Redis::connection()->ping(),
            'queue' => fn () => Queue::connection()->size(),
            'storage' => fn () => $this->storage(),
            'horizon' => fn () => $this->horizon(),
        ];

        $results = [];
        $ready = true;

        foreach ($checks as $name => $check) {
            $started = microtime(true);
            try {
                $check();
                $status = 'ok';
            } catch (Throwable $e) {
                $status = 'fail';
                Log::warning("Readiness check {$name} failed", ['exception' => $e]);
            }

            $counts = $name !== 'horizon' || $this->config->get('observability.health.require_horizon');
            if ($status !== 'ok') {
                $counts ? $ready = false : $status = 'warn';
            }

            $results[$name] = ['status' => $status, 'ms' => round((microtime(true) - $started) * 1000, 1)];
        }

        return ['ready' => $ready, 'checks' => $results];
    }

    /** Writes, reads back and deletes a small probe file. */
    private function storage(): void
    {
        $disk = Storage::disk($this->config->get('observability.health.storage_disk') ?: TenantFiles::disk());
        $path = TenantFiles::platformKey('health', Str::uuid7().'.txt');
        $value = (string) microtime(true);

        try {
            $disk->put($path, $value);

            if ($disk->get($path) !== $value) {
                throw new RuntimeException('The storage probe read back something else.');
            }
        } finally {
            $disk->delete($path);
        }
    }

    private function horizon(): void
    {
        if (count(app(MasterSupervisorRepository::class)->all()) === 0) {
            throw new RuntimeException('No Horizon master supervisor is running.');
        }
    }
}
