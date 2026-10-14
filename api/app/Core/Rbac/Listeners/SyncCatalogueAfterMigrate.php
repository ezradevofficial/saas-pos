<?php

namespace App\Core\Rbac\Listeners;

use App\Core\Rbac\Console\SyncPermissions;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC-01, RBAC-03: every `migrate` (also with nothing to migrate) ends
 * with `permissions:sync`, so a module's new permissions exist and reach
 * the Owner and the role templates without a manual step. The sync is
 * idempotent; the deploy runs it again on its own. Skipped for rollbacks,
 * `--pretend`, and before the catalogue table exists.
 */
class SyncCatalogueAfterMigrate
{
    public function handle(MigrationsEnded|NoPendingMigrations $event): void
    {
        $pretend = $event instanceof MigrationsEnded && ($event->options['pretend'] ?? false);

        if ($event->method !== 'up' || $pretend || ! Schema::connection(SyncPermissions::OWNER_CONNECTION)->hasTable('permissions')) {
            return;
        }

        Artisan::call('permissions:sync');
    }
}
