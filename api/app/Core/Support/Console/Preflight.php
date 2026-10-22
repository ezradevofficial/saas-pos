<?php

namespace App\Core\Support\Console;

use App\Core\Notifications\Sms\SmsSender;
use App\Core\Platform\PlatformDatabase;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Support\EnvironmentGuard;
use App\Core\Support\SmtpProbe;
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
                // Deploy rehearsal (2026-10-22): an unknown name passed as ready while sending nothing.
                $label === 'SMS driver' && ! in_array($config->get('services.sms.driver'), SmsSender::DRIVERS, true) => ['warn', 'SMS_DRIVER '.$config->get('services.sms.driver').' is not a provider this release implements: text messages fail with SmsNotConfigured'],
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

        // ADR 025: the owner's own SMTP mailbox. A wrong password or a blocked
        // port otherwise passes every check and the first verification code
        // never arrives. Connects and signs in; sends nothing.
        $checks['Mail server'] = function () use ($config, $development) {
            $mailer = (string) $config->get('mail.default');
            $probe = $this->laravel->make(SmtpProbe::class);

            return match (true) {
                $development => ['skip', 'not checked here'],
                ! $probe->applies($mailer) => ['skip', "{$mailer} is not an SMTP mailer"],
                default => ($problem = $probe->check($mailer)) === null
                    ? ['ok', 'SMTP connection and sign-in accepted']
                    : ['fail', "SMTP connection or sign-in failed: {$problem}"],
            };
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
        // NFR-07, ADR 025: the host's database and file backups are declared on
        // (BACKUPS_ENABLED) once deploy/host provisioning has turned them on.
        // Staging and the pilot run without them; production warns, never fails.
        $checks['Backups'] = fn () => match (true) {
            $development => ['skip', 'not needed here'],
            (bool) $config->get('observability.backups.enabled') => ['ok', 'declared on (BACKUPS_ENABLED)'],
            $this->laravel->environment('production') => ['warn', 'Backups are off: enable before go-live (docs/backups.md)'],
            default => ['skip', 'off outside production (docs/backups.md)'],
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
