<?php

namespace Tests\Unit\Support;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\HostClock;

// AUTH-09: the TwoTenants fixture names a host suspension (wall clock ahead of
// the monotonic clock) when its setup fails, since the idle timeout then
// refuses its tokens by design.
class HostClockTest extends TestCase
{
    public function test_a_running_host_has_no_suspension(): void
    {
        $clock = HostClock::start();

        $this->assertLessThan(HostClock::NOTABLE_SECONDS, $clock->suspendedSeconds());
        $this->assertSame('', $clock->note());
    }

    public function test_wall_time_beyond_monotonic_time_is_a_suspension(): void
    {
        $clock = HostClock::start();
        $monotonic = hrtime(true);

        // Eleven hours of wall time pass while no monotonic time does (a closed lid).
        $note = $clock->note(microtime(true) + 11 * 3600, $monotonic);

        $this->assertEqualsWithDelta(11 * 3600, $clock->suspendedSeconds(microtime(true) + 11 * 3600, $monotonic), 5);
        $this->assertStringContainsString('suspended for about 660 minutes', $note);
        $this->assertStringContainsString('AUTH-09', $note);
    }

    public function test_carbons_test_clock_is_not_a_suspension(): void
    {
        // Tests that travel in time move Carbon only, never the host clocks.
        $clock = HostClock::start();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(5));

        try {
            $this->assertSame('', $clock->note());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
