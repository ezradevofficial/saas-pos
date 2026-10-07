<?php

namespace App\Core\Rbac\Console;

use App\Core\Rbac\Models\Permission;
use App\Core\Rbac\PermissionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Write the code-declared catalogue to the global `permissions` table
 * (RBAC-01). Idempotent; never deletes (roles may still reference a
 * permission a module stopped declaring). Run on every deploy.
 */
class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Upsert the permission catalogue declared by the modules';

    public function handle(PermissionRegistry $registry, PermissionRegistrar $registrar): int
    {
        $created = 0;

        DB::transaction(function () use ($registry, &$created) {
            foreach ($registry->all() as $entry) {
                $permission = Permission::firstOrNew(['name' => $entry['name'], 'guard_name' => Permission::GUARD]);
                $permission->fill($entry);

                if (! $permission->exists) {
                    $created++;
                }

                $permission->save();
            }
        });

        $registrar->forgetCachedPermissions();

        $stale = Permission::whereNotIn('name', $registry->all()->keys())->pluck('name');

        $this->components->info(sprintf('%d permissions, %d new.', $registry->all()->count(), $created));

        if ($stale->isNotEmpty()) {
            $this->components->warn('No longer declared: '.$stale->implode(', '));
        }

        return self::SUCCESS;
    }
}
