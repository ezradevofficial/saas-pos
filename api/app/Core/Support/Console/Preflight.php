<?php

namespace App\Core\Support\Console;

use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Support\EnvironmentGuard;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * NFR-06: is this host ready to serve? Prints every check and exits 1 when
 * any fails. Never throws: it must run against a broken or stale config, so
 * the deploy runs it after `config:clear` and before `migrate`.
 *
 * Driver and debug checks apply outside `local` and `testing`, like
 * EnvironmentGuard. The key and the database roles are checked everywhere:
 * the runtime role must be subject to row-level security (neither
 * superuser nor BYPASSRLS), and the owner, which bypasses it by design to
 * run migrations (ADR 002), must not be a superuser.
 */
class Preflight extends Command
{
    protected $signature = 'app:preflight';

    protected $description = 'Check this environment is ready to serve (drivers, key, debug, database roles)';

    public function handle(Repository $config): int
    {
        $development = $this->laravel->environment(EnvironmentGuard::DEVELOPMENT);
        $this->components->info(sprintf('Preflight for the %s environment.', $this->laravel->environment()));

        $failed = 0;
        foreach ($this->checks($config, $development) as $label => $check) {
            try {
                [$status, $detail] = $check();
            } catch (Throwable $e) {
                [$status, $detail] = ['fail', $e->getMessage()];
            }

            $failed += $status === 'fail' ? 1 : 0;
            $this->components->twoColumnDetail($label, $this->format($status, $detail));
        }

        if ($failed > 0) {
            $this->components->error("{$failed} check(s) failed. See the pre-deploy checklist in README.md.");

            return self::FAILURE;
        }

        $this->components->info('Ready.');

        return self::SUCCESS;
    }

    /** @return array<string, callable(): array{string, ?string}> status ok|warn|skip|fail, and a detail */
    private function checks(Repository $config, bool $development): array
    {
        $drivers = EnvironmentGuard::checks($config);
        $checks = [];

        foreach ($drivers as $label => $problem) {
            $checks[$label] = fn () => match (true) {
                $development => ['skip', 'development drivers allowed here'],
                $problem !== null => ['fail', $problem],
                $label === 'SMS driver' && $config->get('services.sms.driver') === null => ['warn', 'SMS_DRIVER not set: text messages fail with SmsNotConfigured'],
                default => ['ok', null],
            };
        }

        $checks['Application key'] = fn () => filled($config->get('app.key'))
            ? ['ok', null]
            : ['fail', 'APP_KEY is not set (php artisan key:generate)'];

        $checks['Debug mode'] = fn () => match (true) {
            $development => ['skip', 'allowed here'],
            (bool) $config->get('app.debug') => ['fail', 'APP_DEBUG is true (set APP_DEBUG=false)'],
            default => ['ok', null],
        };

        $checks['Runtime database role'] = fn () => $this->role(TenantContext::CONNECTION, allowBypassRls: false);
        $checks['Owner database role'] = fn () => $this->role(SyncPermissions::OWNER_CONNECTION, allowBypassRls: true);

        return $checks;
    }

    /** @return array{string, ?string} */
    private function role(string $connection, bool $allowBypassRls): array
    {
        $role = DB::connection($connection)->selectOne(
            'select rolname, rolsuper, rolbypassrls from pg_roles where rolname = current_user'
        );

        if ($role->rolsuper) {
            return ['fail', "{$role->rolname} is a superuser"];
        }

        if ($role->rolbypassrls && ! $allowBypassRls) {
            return ['fail', "{$role->rolname} bypasses row-level security (BYPASSRLS)"];
        }

        return ['ok', $role->rolname];
    }

    private function format(string $status, ?string $detail): string
    {
        $tag = match ($status) {
            'ok' => '<fg=green;options=bold>OK</>',
            'warn' => '<fg=yellow;options=bold>WARN</>',
            'skip' => '<fg=gray>SKIP</>',
            default => '<fg=red;options=bold>FAIL</>',
        };

        return $detail === null ? $tag : "<fg=gray>{$detail}</> {$tag}";
    }
}
