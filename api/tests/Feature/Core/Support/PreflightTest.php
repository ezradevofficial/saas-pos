<?php

namespace Tests\Feature\Core\Support;

use App\Core\Support\SmtpProbe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

// NFR-06: `app:preflight` prints every check and exits 1 when one fails.
class PreflightTest extends TestCase
{
    private function production(array $config = []): void
    {
        $this->app['env'] = 'production';
        config(array_merge([
            'app.debug' => false,
            'mail.default' => 'smtp',
            'services.sms.driver' => null,
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            // Real environments never allow the fake payment or fiscal drivers.
            'payments.allow_fake' => false,
            'payments.drivers' => [],
            'billing.drivers' => [],
            'fiscal.allow_fake' => false,
            // HR's identifier digest key (HR-10), separate from APP_KEY.
            'hr.digest_key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        ], $config));
        // ADR 025: the SMTP sign-in is proven against a fake server in SmtpProbeTest.
        $this->smtp(null);
    }

    /** The mail server's answer: null when it accepts the sign-in, else the problem. */
    private function smtp(?string $problem): void
    {
        $this->app->instance(SmtpProbe::class, new class($problem) extends SmtpProbe
        {
            public function __construct(private ?string $problem) {}

            public function check(string $mailer): ?string
            {
                return $this->problem;
            }
        });
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        DB::purge('pgsql');

        parent::tearDown();
    }

    private function preflight(): array
    {
        $code = Artisan::call('app:preflight');

        return [$code, Artisan::output()];
    }

    public function test_a_ready_production_environment_passes(): void
    {
        $this->production();

        [$code, $output] = $this->preflight();

        $this->assertSame(0, $code, $output);
        foreach (['Mail transport', 'SMS driver', 'Cache store', 'Queue connection', 'Redis', 'Mail server', 'Application key', 'Debug mode', 'Runtime database role', 'Owner database role', 'Platform database role', 'Error tracking', 'Backups', 'Metrics access'] as $label) {
            $this->assertStringContainsString($label, $output);
        }
        // No SMS provider yet: a warning, not a failure.
        $this->assertStringContainsString('SMS_DRIVER not set', $output);
        $this->assertStringContainsString('Ready.', $output);
    }

    /** Review M1: in production, /metrics behind an address allow-list alone is not enough. */
    public function test_a_metrics_allow_list_without_a_token_fails_in_production(): void
    {
        $this->production(['observability.metrics.allow' => ['192.168.140.25/32'], 'observability.metrics.token' => null]);

        [$code, $output] = $this->preflight();

        $this->assertSame(1, $code, $output);
        $this->assertMatchesRegularExpression('/Metrics access.*METRICS_TOKEN.*FAIL/s', $output);

        $this->production(['observability.metrics.allow' => ['192.168.140.25/32'], 'observability.metrics.token' => 'a-long-random-scrape-token']);
        [$code, $output] = $this->preflight();
        $this->assertSame(0, $code, $output);
    }

    public function test_development_drivers_debug_and_a_missing_key_fail_in_production(): void
    {
        $this->production([
            'mail.default' => 'log',
            'services.sms.driver' => 'log',
            'cache.default' => 'database',
            'queue.default' => 'sync',
            'app.debug' => true,
            'app.key' => '',
            'hr.digest_key' => '',
        ]);

        [$code, $output] = $this->preflight();

        $this->assertSame(1, $code);
        foreach (['mail transport is log', 'SMS driver is log', "cache store is 'database'", "queue connection is 'sync'", 'APP_DEBUG is true', 'APP_KEY is not set', 'HR_DIGEST_KEY is not set', '7 check(s) failed'] as $problem) {
            $this->assertStringContainsString($problem, $output);
        }
    }

    public function test_development_drivers_are_skipped_in_local(): void
    {
        $this->app['env'] = 'local';
        config(['mail.default' => 'log', 'cache.default' => 'array', 'queue.default' => 'sync', 'app.debug' => true]);

        [$code, $output] = $this->preflight();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('SKIP', $output);
    }

    public function test_a_runtime_role_that_bypasses_row_level_security_fails(): void
    {
        $this->production();
        // Point the runtime connection at the owner role (BYPASSRLS).
        config([
            'database.connections.pgsql.username' => config('database.connections.pgsql_owner.username'),
            'database.connections.pgsql.password' => config('database.connections.pgsql_owner.password'),
        ]);
        DB::purge('pgsql');

        [$code, $output] = $this->preflight();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('bypasses row-level security', $output);
    }

    public function test_an_unreachable_database_is_reported_not_thrown(): void
    {
        $this->production();
        config(['database.connections.pgsql_owner.port' => 1]);
        DB::purge('pgsql_owner');

        [$code, $output] = $this->preflight();

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/Owner database role.*FAIL/s', $output);
        DB::purge('pgsql_owner');
    }

    /** NFR-01, deploy rehearsal 2026-10-22: Redis was never checked, so a wrong client passed and the migration failed. */
    public function test_an_unreachable_redis_fails_and_is_reported_not_thrown(): void
    {
        $this->production();
        config(['database.redis.default.port' => 1]);
        Redis::purge('default');

        try {
            [$code, $output] = $this->preflight();
        } finally {
            config(['database.redis.default.port' => env('REDIS_PORT', 6379)]);
            Redis::purge('default');
        }

        $this->assertSame(1, $code, $output);
        $this->assertMatchesRegularExpression('/Redis .*FAIL/', $output);
    }

    /** Deploy rehearsal 2026-10-22: a provider name the release does not implement sends nothing; warn, never pass silently. */
    public function test_an_sms_driver_this_release_does_not_implement_warns(): void
    {
        $this->production(['services.sms.driver' => 'some-provider']);

        [$code, $output] = $this->preflight();

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/SMS driver.*SMS_DRIVER some-provider is not a provider this release implements.*WARN/', $output);
    }

    /** ADR 025: a wrong mailbox password or a blocked port stops the deploy, not the first verification code. */
    public function test_an_smtp_server_that_refuses_the_sign_in_fails(): void
    {
        $this->production();
        $this->smtp('Expected response code "235" but got code "535"');

        [$code, $output] = $this->preflight();

        $this->assertSame(1, $code, $output);
        $this->assertMatchesRegularExpression('/Mail server.*SMTP connection or sign-in failed.*535.*FAIL/s', $output);

        // A non-SMTP mailer (an API transport) is not probed.
        $this->production(['mail.default' => 'postmark', 'mail.mailers.postmark' => ['transport' => 'postmark']]);
        $this->smtp('never asked');
        [$code, $output] = $this->preflight();
        $this->assertMatchesRegularExpression('/Mail server.*postmark is not an SMTP mailer.*SKIP/', $output);
    }

    /** NFR-07, ADR 025: staging and the pilot run without host backups; production warns until they are declared on. */
    public function test_backups_off_warn_in_production_only(): void
    {
        $this->production(['observability.backups.enabled' => false]);
        [$code, $output] = $this->preflight();
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/Backups.*Backups are off: enable before go-live.*WARN/', $output);

        $this->production(['observability.backups.enabled' => true]);
        [, $output] = $this->preflight();
        $this->assertMatchesRegularExpression('/Backups.*declared on.*OK/', $output);

        $this->production(['observability.backups.enabled' => false]);
        $this->app['env'] = 'staging';
        [, $output] = $this->preflight();
        $this->assertMatchesRegularExpression('/Backups.*off outside production.*SKIP/', $output);
    }
}
