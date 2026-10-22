<?php

namespace App\Core\Support\Console;

use App\Core\Notifications\Sms\SmsAvailability;
use App\Core\Notifications\Sms\SmsSender;
use App\Core\Platform\PlatformDatabase;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Support\EnvironmentGuard;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
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

    /** Why text messages are off: phone sign-up, phone codes and SMS notifications are refused or skipped. */
    private static function emailOnly(mixed $driver): string
    {
        $why = match (true) {
            $driver === null || $driver === '' => 'SMS_DRIVER not set',
            $driver === SmsAvailability::NONE => 'SMS_DRIVER is none',
            ! in_array($driver, SmsSender::DRIVERS, true) => 'SMS_DRIVER '.$driver.' is not a provider this release implements',
            default => 'SMS_DRIVER '.$driver.' is for local and testing only',
        };

        return "SMS unavailable: email-only mode ({$why}; phone sign-up and codes by SMS are refused)";
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
                // AUTH-01..05, NOT-01 (owner decision 2026-10-22): no provider is a supported
                // email-only mode, a warning. Deploy rehearsal (2026-10-22): an unknown name
                // passed as ready while sending nothing.
                $label === 'SMS driver' && ! $this->laravel->make(SmsAvailability::class)->available() => ['warn', self::emailOnly($config->get('services.sms.driver'))],
                default => ['ok', null],
            };
        }

        // NFR-01 (deploy rehearsal 2026-10-22): the cache, queues, locks and
        // metrics all live in Redis; a wrong client or password passed every
        // other check and the first migration then failed.
        $checks['Redis'] = function () use ($config) {
            Redis::connection()->ping();

            return ['ok', (string) $config->get('database.redis.client')];
        };

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
        // ADR 013: platform staff sign in through their own role (DB_PLATFORM_*).
        $checks['Platform database role'] = fn () => $config->get('database.connections.'.PlatformDatabase::CONNECTION.'.username')
            ? $this->role(PlatformDatabase::CONNECTION, allowBypassRls: false)
            : ['warn', 'DB_PLATFORM_* not set: the platform console cannot sign in'];

        // NFR-10: reported, never fatal, so a host can go live before the owner chooses.
        $checks['Error tracking'] = fn () => match (true) {
            $development => ['skip', 'not needed here'],
            filled($config->get('sentry.dsn')) => ['ok', 'Sentry on, release '.($config->get('sentry.release') ?: 'not set')],
            default => ['warn', 'SENTRY_LARAVEL_DSN not set: errors are only in the logs'],
        };
        $checks['Metrics access'] = fn () => match (true) {
            $development => ['skip', 'not needed here'],
            // Review M1: an address allow-list alone can be bypassed by a misrouted proxy.
            $this->laravel->environment('production') && ! filled($config->get('observability.metrics.token')) && $config->get('observability.metrics.allow') !== [] => ['fail', 'METRICS_ALLOW is set without METRICS_TOKEN: set a scrape token in production'],
            filled($config->get('observability.metrics.token')) || $config->get('observability.metrics.allow') !== [] => ['ok', null],
            default => ['warn', 'METRICS_TOKEN and METRICS_ALLOW not set: /metrics answers nobody'],
        };

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
