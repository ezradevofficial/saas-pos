<?php

namespace App\Core\Api;

use App\Core\Api\Models\ApiKey;
use App\Core\Billing\Catalogue\Models\CatalogueBundle;
use App\Core\Billing\Models\Subscription;
use App\Core\Http\ApiErrorRenderer;
use App\Core\Http\ApiException;
use App\Core\Platform\PlatformDatabase;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;

/**
 * API-03 (ADR 020): two one-minute windows per key request, one per key
 * and one per tenant across its keys. The tenant's limit is its plan's
 * (`catalogue_bundles.api_requests_per_minute`), falling back to
 * `api.rate_limits.tenant_per_minute`; a key's is
 * `api.rate_limits.key_per_minute`. A request over either window is
 * refused with 429 `rate_limited` and Retry-After, before it is counted.
 */
class ApiRateLimits
{
    private const DECAY_SECONDS = 60;

    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * Count the request in both windows, or refuse it.
     *
     * @return array{limit: int, remaining: int, reset: int} the tighter window, for the RateLimit-* headers
     */
    public function hit(ApiKey $key): array
    {
        $windows = [
            ['api-key|'.$key->id, $this->keyLimit()],
            ['api-tenant|'.$key->tenant_id, $this->tenantLimit($key->tenant_id)],
        ];

        foreach ($windows as [$name, $max]) {
            if ($this->limiter->tooManyAttempts($name, $max)) {
                $retry = max(1, $this->limiter->availableIn($name));

                throw new ApiException(429, 'rate_limited', ApiErrorRenderer::retryMessage($retry), headers: [
                    'Retry-After' => $retry,
                    'RateLimit-Limit' => $max,
                    'RateLimit-Remaining' => 0,
                    'RateLimit-Reset' => $retry,
                ]);
            }
        }

        $tightest = null;

        foreach ($windows as [$name, $max]) {
            $this->limiter->hit($name, self::DECAY_SECONDS);
            $window = ['limit' => $max, 'remaining' => max(0, $this->limiter->remaining($name, $max)), 'reset' => max(0, $this->limiter->availableIn($name))];

            if ($tightest === null || $window['remaining'] < $tightest['remaining']) {
                $tightest = $window;
            }
        }

        return $tightest;
    }

    public function keyLimit(): int
    {
        return max(1, (int) config('api.rate_limits.key_per_minute', 120));
    }

    /** The plan's limit when its bundle sets one, else the configured default. */
    public function tenantLimit(string $tenantId): int
    {
        $plan = Cache::remember("api-plan-limit|{$tenantId}", (int) config('api.rate_limits.plan_cache_seconds', 300), function () {
            $bundle = Subscription::query()->value('bundle');

            return $bundle === null ? 0 : (int) CatalogueBundle::on(PlatformDatabase::READ)->where('key', $bundle)->value('api_requests_per_minute');
        });

        return $plan > 0 ? $plan : max(1, (int) config('api.rate_limits.tenant_per_minute', 600));
    }
}
