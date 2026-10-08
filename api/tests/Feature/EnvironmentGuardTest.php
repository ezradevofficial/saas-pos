<?php

namespace Tests\Feature;

use App\Core\Notifications\Sms\LogSmsSender;
use App\Core\Notifications\Sms\NullSmsSender;
use App\Core\Notifications\Sms\SmsNotConfigured;
use App\Core\Notifications\Sms\SmsSender;
use App\Core\Support\EnvironmentGuard;
use RuntimeException;
use Tests\TestCase;

// I6: development drivers (log mail, log SMS, non-Redis cache or queue)
// never run outside local and testing.
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
            ['services.sms.driver' => 'log'],
            ['cache.default' => 'database'],
            ['cache.default' => 'array'],
            ['queue.default' => 'sync'],
            ['queue.default' => 'database'],
        ] as $config) {
            $this->production($config);

            try {
                EnvironmentGuard::enforce($this->app);
                $this->fail('Booted with '.json_encode($config));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('production environment uses development drivers', $e->getMessage());
                $this->assertStringContainsString('README.md', $e->getMessage());
            }
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
}
