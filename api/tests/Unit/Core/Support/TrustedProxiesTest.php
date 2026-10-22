<?php

namespace Tests\Unit\Core\Support;

use App\Core\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** A real environment without TRUSTED_PROXIES logs a warning at boot (review finding, rate limits behind the NodeBalancer). */
class TrustedProxiesTest extends TestCase
{
    public function test_an_empty_value_warns_outside_local_and_testing(): void
    {
        Log::spy();

        $this->assertTrue(TrustedProxies::warnIfMissing('production', null));
        $this->assertTrue(TrustedProxies::warnIfMissing('staging', '*'));
        $this->assertFalse(TrustedProxies::warnIfMissing('production', '192.168.255.0/24'));
        $this->assertFalse(TrustedProxies::warnIfMissing('local', null));
        $this->assertFalse(TrustedProxies::warnIfMissing('testing', ''));
        // Deploy rehearsal 2026-10-22: a host reached directly says so, and is not warned on every boot.
        $this->assertFalse(TrustedProxies::warnIfMissing('staging', 'none'));

        Log::shouldHaveReceived('warning')->twice();
    }

    /** Deploy rehearsal 2026-10-22: with config cached env() is null, so boot reads config and warns only when it is empty. */
    public function test_boot_reads_the_cached_config_not_the_environment(): void
    {
        Log::spy();
        $this->app['env'] = 'production';

        try {
            config(['app.trusted_proxies' => '192.168.255.0/24']);
            TrustedProxies::boot($this->app);
            Log::shouldNotHaveReceived('warning');

            config(['app.trusted_proxies' => null]);
            TrustedProxies::boot($this->app);
            Log::shouldHaveReceived('warning')->once();
        } finally {
            $this->app['env'] = 'testing';
            TrustProxies::flushState();
        }
    }
}
