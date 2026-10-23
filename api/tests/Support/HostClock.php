<?php

namespace Tests\Support;

/**
 * Detects the host being suspended (a laptop's lid closed) during a long
 * fixture. The wall clock (microtime) keeps running while the host sleeps;
 * the monotonic clock (hrtime: CLOCK_UPTIME_RAW on macOS, CLOCK_MONOTONIC on
 * Linux) does not. A suspension longer than the idle timeout ends the
 * fixture's sessions (AUTH-09), which is correct product behaviour, so a
 * setup failure after one is named as such instead of looking like a bug.
 * Carbon's test clock does not affect either reading.
 */
final class HostClock
{
    /** Suspensions shorter than this are not worth naming (scheduling noise). */
    public const NOTABLE_SECONDS = 60;

    private function __construct(
        private readonly float $wallStart,
        private readonly int $monotonicStart,
    ) {}

    public static function start(): self
    {
        return new self(microtime(true), hrtime(true));
    }

    /** Seconds the host spent suspended since start(): wall time elapsed minus monotonic time elapsed. */
    public function suspendedSeconds(?float $wallNow = null, ?int $monotonicNow = null): float
    {
        $wall = ($wallNow ?? microtime(true)) - $this->wallStart;
        $monotonic = (($monotonicNow ?? hrtime(true)) - $this->monotonicStart) / 1e9;

        return max(0.0, $wall - $monotonic);
    }

    /** A sentence for a failure message when the host was suspended, else ''. */
    public function note(?float $wallNow = null, ?int $monotonicNow = null): string
    {
        $seconds = $this->suspendedSeconds($wallNow, $monotonicNow);

        if ($seconds < self::NOTABLE_SECONDS) {
            return '';
        }

        return sprintf(
            ' The host was suspended for about %d minutes during the setup (wall clock ahead of the monotonic clock):'
            .' tokens idle for longer than the session timeout are refused (AUTH-09), so a 401 here is expected. Keep the machine awake and rerun.',
            (int) round($seconds / 60),
        );
    }
}
