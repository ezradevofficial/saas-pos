<?php

namespace Tests\Feature\Core\Support;

use App\Core\Notifications\Sms\LogSmsSender;
use App\Core\Notifications\Sms\NullSmsSender;
use App\Core\Notifications\Sms\SmsNotConfigured;
use App\Core\Notifications\Sms\SmsSender;
use App\Core\Support\EnvironmentGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

// I6 / NFR-06: development drivers (log mail, log SMS, non-Redis cache or
// queue) never serve traffic outside local and testing. The guard runs at
// the runtime entry points (HTTP requests, queue workers, the scheduler),
// never while the app boots, so deploy and bootstrap commands
// (`package:discover` during `composer install`, `config:clear`) work even
// with the previous release's cached config.
class EnvironmentGuardTest extends TestCase
{
    private function production(array $config = []): void
    {
        $this->app['env'] = 'production';
        config(array_merge([
            'mail.default' => 'smtp',
            'services.sms.driver' => null,
            'cache.default' => 'redis',
            'queue.default' => 'redis',
        ], $config));
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    public function test_a_production_environment_with_real_drivers_boots(): void
    {
        $this->production();

        $this->assertTrue(EnvironmentGuard::applies($this->app));
        $this->assertSame([], EnvironmentGuard::problems(config()));
        EnvironmentGuard::enforce($this->app);
    }

    public function test_each_development_driver_is_refused_in_production(): void
    {
        foreach ([
            ['mail.default' => 'log'],
            ['mail.default' => 'array'],
            // A real mailer name whose transport is the log, or a failover to it.
            ['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'log'],
            ['mail.default' => 'failover'],
            ['mail.default' => 'missing'],
            // MAIL_MAILER=null in .env is PHP null; an empty value is ''.
            ['mail.default' => null],
            ['mail.default' => ''],
            ['services.sms.driver' => 'log'],
            ['cache.default' => 'database'],
            ['cache.default' => 'array'],
            ['queue.default' => 'sync'],
            ['queue.default' => 'database'],
        ] as $config) {
            $this->production($config);
            $refused = null;

            try {
                EnvironmentGuard::enforce($this->app);
            } catch (RuntimeException $e) {
                $refused = $e->getMessage();
            }

            $this->assertNotNull($refused, 'Booted with '.json_encode($config));
            $this->assertStringContainsString('production environment uses development drivers', $refused);
            $this->assertStringContainsString('README.md', $refused);

            config(['mail.mailers.smtp.transport' => 'smtp']);
        }
    }

    public function test_several_problems_are_reported_together(): void
    {
        $this->production(['mail.default' => 'log', 'cache.default' => 'file']);

        $this->assertCount(2, EnvironmentGuard::problems(config()));
    }

    public function test_local_and_testing_may_use_development_drivers(): void
    {
        config(['mail.default' => 'log', 'services.sms.driver' => 'log', 'cache.default' => 'array', 'queue.default' => 'sync']);

        foreach (EnvironmentGuard::DEVELOPMENT as $env) {
            $this->app['env'] = $env;
            $this->assertFalse(EnvironmentGuard::applies($this->app));
            EnvironmentGuard::enforce($this->app);
        }
    }

    public function test_an_http_request_is_refused_with_development_drivers(): void
    {
        $this->production(['mail.default' => 'log']);
        $this->withoutExceptionHandling();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mail transport is log');

        $this->get('/up');
    }

    public function test_an_http_request_is_served_with_real_drivers(): void
    {
        $this->production();

        $this->get('/up')->assertOk();
    }

    public function test_a_queue_worker_is_refused_on_its_first_loop(): void
    {
        $this->production(['queue.default' => 'redis', 'cache.default' => 'database']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cache store is');

        event(new Looping('redis', 'default'));
    }

    public function test_a_queue_worker_checks_only_its_first_loop(): void
    {
        $this->production();
        event(new Looping('redis', 'default'));

        // Intent: the check runs on the first tick only (the loop runs every
        // few seconds). Had this second tick been checked, the database cache
        // below would have thrown; the worker re-checks when it restarts.
        config(['cache.default' => 'database']);
        event(new Looping('redis', 'default'));

        $this->assertSame(1, $this->problemsOnFreshWorker(), 'a new worker process still checks');
    }

    /** A fresh guard listener (a new worker) sees the problem on its first tick. */
    private function problemsOnFreshWorker(): int
    {
        $events = new Dispatcher($this->app);
        EnvironmentGuard::listen($this->app, $events);

        try {
            $events->dispatch(new Looping('redis', 'default'));
        } catch (RuntimeException) {
            return 1;
        }

        return 0;
    }

    public function test_the_maintenance_page_is_served_even_when_the_guard_would_refuse(): void
    {
        $this->production(['mail.default' => 'log']);
        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return ['status' => 503, 'retry' => 60, 'except' => []];
            }
        });

        $this->postJson('/api/v1/auth/sign-in')->assertStatus(503)->assertHeader('Retry-After', '60');
        $this->get('/')->assertStatus(503);

        // `/up` is never in maintenance (Laravel's health route): it keeps
        // reporting the misconfiguration to the load balancer.
        $this->getJson('/up')->assertStatus(500);
    }

    public function test_the_scheduler_and_workers_are_refused_with_development_drivers(): void
    {
        // Under unit tests Laravel does not route Symfony's console events;
        // the real `php artisan` does (see the stale config test below).
        $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
        $this->production(['services.sms.driver' => 'log']);

        foreach (EnvironmentGuard::RUNTIME_COMMANDS as $command) {
            $refused = null;

            try {
                Artisan::call($command, $command === 'queue:work' ? ['--once' => true] : []);
            } catch (RuntimeException $e) {
                $refused = $e->getMessage();
            }

            $this->assertNotNull($refused, "{$command} ran with development drivers");
            $this->assertStringContainsString('SMS driver is log', $refused);
        }
    }

    public function test_bootstrap_commands_do_not_enforce_in_process(): void
    {
        $this->production(['mail.default' => 'log', 'cache.default' => 'database']);

        $this->assertSame(0, Artisan::call('about', ['--only' => 'environment']));
        $this->assertSame(0, Artisan::call('route:list', ['--path' => 'up']));
    }

    /**
     * The Sprint 1 failure: after the rsync, `composer install` runs
     * `artisan package:discover` with the previous release's cached config.
     * When that config has a log mailer and a database cache, booting
     * threw, so the install and even `config:clear` failed.
     */
    public function test_package_discover_and_config_clear_work_with_a_stale_production_config_cache(): void
    {
        $dir = sys_get_temp_dir().'/env-guard-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $cached = $this->writeStaleProductionConfig($dir);

        $env = [
            'APP_ENV' => 'production',
            'APP_CONFIG_CACHE' => $cached,
            'APP_PACKAGES_CACHE' => "{$dir}/packages.php",
            'APP_SERVICES_CACHE' => "{$dir}/services.php",
        ];

        try {
            $discover = Process::path(base_path())->env($env)->run(['php', 'artisan', 'package:discover']);
            $this->assertSame(0, $discover->exitCode(), $discover->output().$discover->errorOutput());
            $this->assertFileExists("{$dir}/packages.php");

            // The stale config is still read: preflight reports it (exit 1) without throwing.
            $preflight = Process::path(base_path())->env($env)->run(['php', 'artisan', 'app:preflight']);
            $this->assertSame(1, $preflight->exitCode(), $preflight->output().$preflight->errorOutput());
            $this->assertStringContainsString('mail transport is log', $preflight->output());
            $this->assertStringNotContainsString('RuntimeException', $preflight->output().$preflight->errorOutput());

            // The runtime entry points still refuse it.
            $schedule = Process::path(base_path())->env($env)->run(['php', 'artisan', 'schedule:run']);
            $this->assertNotSame(0, $schedule->exitCode());
            $this->assertStringContainsString('mail transport is log', $schedule->output().$schedule->errorOutput());

            $clear = Process::path(base_path())->env($env)->run(['php', 'artisan', 'config:clear']);
            $this->assertSame(0, $clear->exitCode(), $clear->output().$clear->errorOutput());
            $this->assertFileDoesNotExist($cached);
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            rmdir($dir);
        }
    }

    public function test_sms_goes_to_the_log_only_in_local_and_testing(): void
    {
        $this->app->forgetInstance(SmsSender::class);
        $this->assertInstanceOf(LogSmsSender::class, $this->app->make(SmsSender::class));

        $this->production();
        $sender = $this->app->make(SmsSender::class);
        $this->assertInstanceOf(NullSmsSender::class, $sender);

        $this->expectException(SmsNotConfigured::class);
        $sender->send('+254700000001', 'Your code is 123456');
    }

    public function test_the_defaults_are_redis(): void
    {
        $cache = require base_path('config/cache.php');
        $queue = require base_path('config/queue.php');

        // The phpunit environment overrides both; the file defaults are redis.
        $this->assertStringContainsString("env('CACHE_STORE', 'redis')", file_get_contents(base_path('config/cache.php')));
        $this->assertStringContainsString("env('QUEUE_CONNECTION', 'redis')", file_get_contents(base_path('config/queue.php')));
        $this->assertArrayHasKey('redis', $cache['stores']);
        $this->assertArrayHasKey('redis', $queue['connections']);
    }

    /** The previous release's `config:cache` output, with development drivers. */
    private function writeStaleProductionConfig(string $dir): string
    {
        $config = config()->all();
        $config['app']['env'] = 'production';
        $config['app']['debug'] = false;
        $config['mail']['default'] = 'log';
        $config['cache']['default'] = 'database';
        $config['queue']['default'] = 'redis';

        $path = "{$dir}/config.php";
        file_put_contents($path, '<?php return '.var_export($config, true).';'.PHP_EOL);

        return $path;
    }
}
