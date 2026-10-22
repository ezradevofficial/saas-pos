<?php

namespace Tests\Feature\Core\Support;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * NFR-01: the Horizon master exits as soon as its memory passes
 * horizon.memory_limit. The stock 64 MB was below what the booted app
 * needs, so in the deploy rehearsal (2026-10-22) Horizon stopped right
 * after starting and no queued job ever ran. The limit must leave room
 * above a freshly booted app, measured as Horizon measures it.
 */
class HorizonMemoryTest extends TestCase
{
    public function test_the_master_limit_leaves_room_above_a_booted_app(): void
    {
        $boot = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            echo memory_get_usage() / 1024 / 1024;
            PHP;

        $process = new Process([PHP_BINARY, '-r', $boot], base_path());
        $process->mustRun();
        $booted = (float) $process->getOutput();
        $limit = (int) config('horizon.memory_limit');

        $this->assertGreaterThan(0, $booted);
        $this->assertGreaterThanOrEqual(1.5 * $booted, $limit, "Horizon's memory_limit ({$limit} MB) leaves too little room above a booted app ({$booted} MB).");
    }
}
