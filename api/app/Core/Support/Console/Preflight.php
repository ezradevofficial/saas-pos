<?php

namespace App\Core\Support\Console;

use App\Core\Files\TenantFiles;
use App\Core\Notifications\Sms\SmsSender;
use App\Core\Platform\PlatformDatabase;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Support\EnvironmentGuard;
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

        // NFR-07: tenant files live in Linode Object Storage. In production the
        // tenant files disk must be the object store and this host must be
        // able to write, read back and delete a probe object there.
        $checks['Object storage'] = fn () => $this->objectStorage($config, $development);

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
    private function objectStorage(Repository $config, bool $development): array
    {
        $disk = TenantFiles::disk();

        if (! TenantFiles::isObjectStore($disk)) {
            return match (true) {
                $development => ['skip', "tenant files on the local disk [{$disk}]"],
                $this->laravel->environment('production') => ['fail', "MEDIA_DISK is [{$disk}], not the object store: set MEDIA_DISK=s3 and the AWS_* variables (docs/deploy-linode.md, Object Storage)"],
                default => ['warn', "tenant files on this server's disk [{$disk}]: set MEDIA_DISK=s3 (docs/deploy-linode.md, Object Storage)"],
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
