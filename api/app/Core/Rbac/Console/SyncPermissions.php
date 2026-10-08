<?php

namespace App\Core\Rbac\Console;

use App\Core\Audit\AuditContext;
use App\Core\Rbac\Models\Permission;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Rbac\RoleTemplates;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Write the code-declared catalogue to the global `permissions` table
 * (RBAC-01). Idempotent; never deletes (roles may still reference a
 * permission a module stopped declaring). Run on every deploy.
 *
 * The catalogue is written as the schema owner (OWNER_CONNECTION): the
 * runtime role may only read it, since a permission row is shared by every
 * tenant (ADR 006).
 *
 * Then every tenant's system roles are re-expanded from their templates
 * (RBAC-03), so a permission added to the catalogue reaches the Owner,
 * Admin and every other template whose patterns match it. Tenant ids are
 * listed as the owner; each refresh runs on the runtime connection inside
 * that tenant's context (row-level security applies, whatever the default
 * connection, e.g. when seeding as the owner), is audited as
 * `rbac.role.permissions_update` with no actor, and flushes that tenant's
 * permission cache once committed. Custom roles are never touched.
 */
class SyncPermissions extends Command
{
    public const OWNER_CONNECTION = 'pgsql_owner';

    protected $signature = 'permissions:sync';

    protected $description = 'Upsert the permission catalogue declared by the modules';

    public function handle(
        PermissionRegistry $registry,
        PermissionRegistrar $registrar,
        TenantContext $tenants,
        RoleTemplates $templates,
        AuditContext $audit,
    ): int {
        $created = 0;

        DB::connection(self::OWNER_CONNECTION)->transaction(function () use ($registry, &$created) {
            foreach ($registry->all() as $entry) {
                $permission = Permission::on(self::OWNER_CONNECTION)
                    ->firstOrNew(['name' => $entry['name'], 'guard_name' => Permission::GUARD]);
                $permission->fill($entry);

                if (! $permission->exists) {
                    $created++;
                }

                $permission->save();
            }
        });

        $registrar->forgetCachedPermissions();

        $refreshed = $this->refreshSystemRoles($tenants, $templates, $registrar, $audit);

        $stale = Permission::whereNotIn('name', $registry->all()->keys())->pluck('name');

        $this->components->info(sprintf(
            '%d permissions, %d new; system roles refreshed in %d tenants.',
            $registry->all()->count(), $created, $refreshed,
        ));

        if ($stale->isNotEmpty()) {
            $this->components->warn('No longer declared: '.$stale->implode(', '));
        }

        return self::SUCCESS;
    }

    /** @return int the number of tenants refreshed */
    private function refreshSystemRoles(
        TenantContext $tenants,
        RoleTemplates $templates,
        PermissionRegistrar $registrar,
        AuditContext $audit,
    ): int {
        // The system acts: no user, device or request is recorded (AUD-02).
        $audit->reset();

        $ids = DB::connection(self::OWNER_CONNECTION)->table('tenants')->orderBy('id')->pluck('id');

        // A seeder run with `--database=pgsql_owner` (composer migrate:fresh)
        // makes the owner, which bypasses row-level security, the default
        // connection. Models without an explicit connection (roles, their
        // permissions, audit entries) must stay on the runtime role.
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection(TenantContext::CONNECTION);

        try {
            foreach ($ids as $tenantId) {
                $tenants->run($tenantId, function () use ($templates, $registrar) {
                    $templates->refresh();
                    // Committed by now: drop this tenant's cache key.
                    $registrar->forgetCachedPermissions();
                });
            }
        } finally {
            DB::setDefaultConnection($previous);
        }

        return $ids->count();
    }
}
