<?php

namespace App\Core\Observability\Metrics;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * NFR-10: counters kept between scrapes, keyed by their Prometheus series
 * (`name{label="value"}`). `redis` is shared by every API host; `array`
 * lives in this process (tests). A metrics failure never fails a request.
 */
class MetricsStore
{
    private const KEY = 'metrics:series';

    /** @var array<string, float> */
    private array $memory = [];

    public function __construct(private readonly string $driver, private readonly string $connection) {}

    /** @param array<string, float> $increments series => amount */
    public function add(array $increments): void
    {
        if ($increments === []) {
            return;
        }

        if ($this->driver !== 'redis') {
            foreach ($increments as $series => $by) {
                $this->memory[$series] = ($this->memory[$series] ?? 0) + $by;
            }

            return;
        }

        try {
            Redis::connection($this->connection)->pipeline(function ($pipe) use ($increments) {
                foreach ($increments as $series => $by) {
                    $pipe->hincrbyfloat(self::KEY, $series, $by);
                }
            });
        } catch (Throwable) {
            // Metrics are best effort: the request already succeeded.
        }
    }

    /** @return array<string, float> */
    public function all(): array
    {
        if ($this->driver !== 'redis') {
            return $this->memory;
        }

        try {
            return array_map('floatval', (array) Redis::connection($this->connection)->hgetall(self::KEY));
        } catch (Throwable) {
            return [];
        }
    }

    public function flush(): void
    {
        $this->memory = [];

        if ($this->driver === 'redis') {
            try {
                Redis::connection($this->connection)->del(self::KEY);
            } catch (Throwable) {
                // Nothing to flush.
            }
        }
    }
}
