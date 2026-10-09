<?php

namespace App\Core\Branding\Console;

use App\Core\Audit\AuditContext;
use App\Core\Audit\Auditor;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BR-07: the platform hides or shows "Powered by" for a tenant (a flag
 * until plans exist in phase 6; tenants cannot change it themselves).
 * Runs in the tenant's context on the runtime connection and is audited
 * as `core.branding.hide_platform`.
 *
 *   php artisan tenant:branding {tenant-id} --hide-platform
 *   php artisan tenant:branding {tenant-id} --show-platform
 */
class TenantBrandingCommand extends Command
{
    protected $signature = 'tenant:branding {tenant : The tenant id} {--hide-platform : Hide "Powered by"} {--show-platform : Show "Powered by" again}';

    protected $description = 'Hide or show the platform branding for a tenant (BR-07)';

    public function handle(TenantContext $tenants, Auditor $auditor, AuditContext $audit): int
    {
        $id = (string) $this->argument('tenant');
        $hide = (bool) $this->option('hide-platform');
        $show = (bool) $this->option('show-platform');

        if (! Str::isUuid($id) || $hide === $show) {
            $this->components->error('Give a tenant id and exactly one of --hide-platform or --show-platform.');

            return self::INVALID;
        }

        $audit->reset();

        $done = $tenants->run($id, fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($id, $hide, $auditor) {
            $tenant = Tenant::query()->whereKey($id)->lockForUpdate()->first();

            if ($tenant === null) {
                return false;
            }

            $settings = $tenant->settings ?? [];
            $before = (bool) ($settings['branding']['hide_platform'] ?? false);
            $settings['branding'] = [...(array) ($settings['branding'] ?? []), 'hide_platform' => $hide];
            $tenant->settings = $settings;
            $tenant->save();

            if ($before !== $hide) {
                $auditor->record('core.branding.hide_platform', $tenant, ['hide_platform' => $before], ['hide_platform' => $hide]);
            }

            return true;
        }));

        if (! $done) {
            $this->components->error('No such tenant.');

            return self::FAILURE;
        }

        $this->components->info($hide ? 'Platform branding hidden.' : 'Platform branding shown.');

        return self::SUCCESS;
    }
}
