<?php

namespace App\Core\Observability\Metrics;

/**
 * NFR-02, NFR-10: request counts and a latency histogram per route
 * pattern (never the raw path, so ids never become labels) and method.
 */
class HttpMetrics
{
    /** @param list<float> $buckets */
    public function __construct(private readonly MetricsStore $store, private readonly array $buckets) {}

    /** Methods kept as labels; anything else is OTHER, so a client cannot invent label values. */
    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public function record(string $method, string $route, int $status, float $seconds): void
    {
        $method = in_array(strtoupper($method), self::METHODS, true) ? strtoupper($method) : 'OTHER';
        $labels = sprintf('method="%s",route="%s"', self::escape($method), self::escape($route));
        $increments = [
            sprintf('http_requests_total{%s,status="%dxx"}', $labels, intdiv($status, 100)) => 1,
            sprintf('http_request_duration_seconds_sum{%s}', $labels) => $seconds,
            sprintf('http_request_duration_seconds_count{%s}', $labels) => 1,
        ];

        foreach ($this->buckets as $bucket) {
            if ($seconds <= $bucket) {
                $increments[sprintf('http_request_duration_seconds_bucket{%s,le="%s"}', $labels, self::number($bucket))] = 1;
            }
        }
        $increments[sprintf('http_request_duration_seconds_bucket{%s,le="+Inf"}', $labels)] = 1;

        $this->store->add($increments);
    }

    public static function escape(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    public static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
