<?php

namespace App\Core\Support\Console;

use App\Core\Files\TenantFiles;
use App\Core\Notifications\Sms\SmsAvailability;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        // NFR-07: tenant files live in Linode Object Storage (on as soon as
        // AWS_BUCKET is set). In production the tenant files disk must be the object store and this host must be
        // able to write, read back and delete a probe object there.
        $checks['Object storage'] = fn () => $this->objectStorage($config, $development);

        // NFR-10: reported, never fatal, so a host can go live before the owner chooses.
        $checks['Error tracking'] = fn () => match (true) {
            $development => ['skip', 'not needed here'],
            filled($config->get('sentry.dsn')) => ['ok', 'Sentry on, release '.($config->get('sentry.release') ?: 'not set')],
            default => ['warn', 'SENTRY_LARAVEL_DSN not set: errors are only in the logs'],
        };
        // NFR-07, ADR 025: the host's database backups (WAL-G) are declared on
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
    private function objectStorage(Repository $config, bool $development): array
    {
        $disk = TenantFiles::disk();

        if (! TenantFiles::isObjectStore($disk)) {
            return match (true) {
                $development => ['skip', "tenant files on the local disk [{$disk}]"],
                $this->laravel->environment('production') => ['fail', "tenant files on this server's disk [{$disk}], not the object store: set the AWS_* variables (AWS_BUCKET switches it on) and leave MEDIA_DISK unset (docs/deploy-linode.md, Object Storage)"],
                default => ['warn', "tenant files on this server's disk [{$disk}]: set the AWS_* variables (AWS_BUCKET switches the object store on) and leave MEDIA_DISK unset (docs/deploy-linode.md, Object Storage)"],
            };
        }

        foreach (['key' => 'AWS_ACCESS_KEY_ID', 'secret' => 'AWS_SECRET_ACCESS_KEY', 'region' => 'AWS_DEFAULT_REGION', 'bucket' => 'AWS_BUCKET', 'endpoint' => 'AWS_ENDPOINT'] as $setting => $variable) {
            if (! filled($config->get("filesystems.disks.{$disk}.{$setting}"))) {
                return ['fail', "{$variable} is not set"];
            }
        }

        $storage = Storage::disk($disk);
        $key = TenantFiles::platformKey('preflight', Str::uuid7().'.txt');
        $value = Str::random(32);

        try {
            if (! $storage->put($key, $value)) {
                return ['fail', 'the bucket refused a probe write'];
            }

            if ($storage->get($key) !== $value) {
                return ['fail', 'the probe object read back something else'];
            }
        } finally {
            rescue(fn () => $storage->delete($key), report: false);
        }

        if ($storage->exists($key)) {
            return ['fail', 'the probe object could not be deleted'];
        }

        return ['ok', (string) $config->get("filesystems.disks.{$disk}.bucket")];
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
