<?php

namespace App\Core\Observability\Console;

use App\Core\Observability\Health\Readiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * NFR-01: writes the public status snapshot (status.json) from the
 * readiness checks, every minute (scheduled on one server). It names
 * components and their state only: no hosts, timings or errors. A static
 * status page can read it (docs/hardening.md, Status page).
 */
class StatusSnapshot extends Command
{
    protected $signature = 'health:status';

    protected $description = 'Write the public status snapshot (status.json) from the readiness checks';

    /** Public component names for each readiness check. */
    private const COMPONENTS = [
        'api' => ['database', 'redis', 'storage'],
        'background_jobs' => ['queue', 'horizon'],
    ];

    public function handle(Readiness $readiness): int
    {
        $result = $readiness->run();
        $components = [];

        foreach (self::COMPONENTS as $component => $checks) {
            $states = array_map(fn (string $check) => $result['checks'][$check]['status'] ?? 'fail', $checks);
            $components[$component] = match (true) {
                in_array('fail', $states, true) => 'down',
                in_array('warn', $states, true) => 'degraded',
                default => 'operational',
            };
        }

        $overall = match (true) {
            in_array('down', $components, true) => 'down',
            in_array('degraded', $components, true) => 'degraded',
            default => 'operational',
        };

        $snapshot = [
            'status' => $overall,
            'components' => $components,
            'generated_at' => now()->utc()->toIso8601String(),
        ];

        Storage::disk(config('observability.status.disk'))->put(
            config('observability.status.path'),
            json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $this->line(json_encode($snapshot, JSON_UNESCAPED_SLASHES));

        return $overall === 'down' ? self::FAILURE : self::SUCCESS;
    }
}
