<?php

namespace App\Core\Sync\Console;

use App\Core\Sync\SyncLag;
use Illuminate\Console\Command;

/**
 * NFR-04: `php artisan sync:lag` prints how far device sync is held back
 * (SyncLag) as JSON; exits 1 above `sync.lag_warning_seconds`, for
 * monitoring. Runtime connection only.
 */
class SyncLagCommand extends Command
{
    protected $signature = 'sync:lag';

    protected $description = 'Show how far a long transaction holds back POS device sync';

    public function handle(SyncLag $lag): int
    {
        $measured = $lag->check();

        if ($measured === null) {
            $this->error('The database did not answer.');

            return self::FAILURE;
        }

        $this->line((string) json_encode($measured));

        return $lag->lagging($measured) ? self::FAILURE : self::SUCCESS;
    }
}
