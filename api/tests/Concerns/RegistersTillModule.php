<?php

namespace Tests\Concerns;

use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Modules\POS\PosServiceProvider;

/**
 * The `pos` module and till permissions as the POS module names them (the
 * system role templates already grant `pos.*` to managers and some to
 * cashiers), so staff, PIN and override tests have till permissions before
 * the module itself exists. Registering again is harmless.
 */
trait RegistersTillModule
{
    public const TILL_PERMISSIONS = PosServiceProvider::PERMISSIONS;

    /** Register the module and its permissions, synced to the catalogue when missing. */
    protected function registerTillModule(): void
    {
        app(ModuleRegistry::class)->register('pos');
        app(PermissionRegistry::class)->register('pos', self::TILL_PERMISSIONS);

        $names = [];
        foreach (self::TILL_PERMISSIONS as $resource => $actions) {
            foreach ($actions as $action) {
                $names[] = "pos.{$resource}.{$action}";
            }
        }

        if (DB::connection(SyncPermissions::OWNER_CONNECTION)->table('permissions')->whereIn('name', $names)->count() < count($names)) {
            $this->syncPermissionCatalogue();
        }
    }
}
