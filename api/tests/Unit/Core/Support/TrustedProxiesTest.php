<?php

namespace Tests\Unit\Core\Support;

use App\Core\Support\TrustedProxies;
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

        Log::shouldHaveReceived('warning')->twice();
    }
}
