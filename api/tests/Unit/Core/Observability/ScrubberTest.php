<?php

namespace Tests\Unit\Core\Observability;

use App\Core\Observability\Logging\ScrubProcessor;
use App\Core\Observability\Scrubber;
use App\Core\Observability\SentryEvents;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\UserDataBag;
use Tests\TestCase;

/**
 * NFR-10: the PHP scrubber passes the same fixtures as @app/scrub (web and
 * POS), from the same rules, and is applied to Sentry events and logs.
 */
class ScrubberTest extends TestCase
{
    private static function fixtures(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 5).'/packages/scrub/fixtures.json'), true);
    }

    public function test_the_api_copy_of_the_rules_matches_the_shared_rules(): void
    {
        $this->assertFileEquals(dirname(__DIR__, 5).'/packages/scrub/rules.json', Scrubber::rulesPath(), 'Copy packages/scrub/rules.json to api/config/scrub-rules.json.');
    }

    public static function strings(): array
    {
        return array_map(fn ($case) => [$case[0], $case[1]], self::fixtures()['strings']);
    }

    #[DataProvider('strings')]
    public function test_strings(string $input, string $expected): void
    {
        $this->assertSame($expected, Scrubber::scrubString($input));
    }

    public function test_objects(): void
    {
        foreach (self::fixtures()['objects'] as [$input, $expected]) {
            $this->assertSame($expected, Scrubber::scrub($input), json_encode($input));
        }
    }

    public function test_keys(): void
    {
        foreach (self::fixtures()['sensitiveKeys'] as $key) {
            $this->assertTrue(Scrubber::isSensitiveKey($key), $key);
        }
        foreach (self::fixtures()['plainKeys'] as $key) {
            $this->assertFalse(Scrubber::isSensitiveKey($key), $key);
        }
    }

    public function test_an_exception_becomes_a_scrubbed_summary(): void
    {
        $out = Scrubber::scrub(['exception' => new RuntimeException('insert failed for amina@example.com')]);

        $this->assertSame(RuntimeException::class, $out['exception']['class']);
        $this->assertSame('insert failed for [email]', $out['exception']['message']);
    }

    public function test_log_records_are_scrubbed(): void
    {
        $record = new LogRecord(new \DateTimeImmutable, 'app', Level::Warning, 'STK push to +254712345678 failed', [
            'password' => 'hunter22',
            'request_id' => '0199f5a2-0000-7000-8000-000000000001',
        ], ['tenant_id' => '0199f5a2-1111-7222-8333-444455556666']);

        $out = (new ScrubProcessor)($record);

        $this->assertSame('STK push to [phone] failed', $out->message);
        $this->assertSame('[redacted]', $out->context['password']);
        $this->assertSame('0199f5a2-0000-7000-8000-000000000001', $out->context['request_id']);
        $this->assertSame('0199f5a2-1111-7222-8333-444455556666', $out->extra['tenant_id']);
    }

    public function test_sentry_events_are_scrubbed_and_keep_only_the_user_id_and_tenant_tag(): void
    {
        $event = Event::createEvent();
        $event->setMessage('Mail to amina@example.com bounced');
        $event->setExceptions([new ExceptionDataBag(new RuntimeException('Card 4111 1111 1111 1111 refused'))]);
        $event->setRequest([
            'url' => 'https://api.example/api/v1/media/x?signature=abc123',
            'headers' => ['Authorization' => ['Bearer 1|abcdefghijklmnopqrstuvwxyz'], 'Accept' => ['application/json']],
            'data' => ['pin' => '1234', 'amount_minor' => 1200],
        ]);
        $event->setExtra(['phone' => '0712345678']);
        $event->setTags(['tenant_id' => '0199f5a2-1111-7222-8333-444455556666', 'api_key' => 'k']);
        $event->setUser(new UserDataBag('u-1', 'amina@example.com', '197.0.0.1', 'Amina'));
        $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'sign-in for amina@example.com', ['token' => 'x'])]);

        $out = SentryEvents::beforeSend($event);

        $this->assertSame('Mail to [email] bounced', $out->getMessage());
        $this->assertSame('Card [number] refused', $out->getExceptions()[0]->getValue());
        $this->assertSame('https://api.example/api/v1/media/x?signature=[redacted]', $out->getRequest()['url']);
        $this->assertSame('[redacted]', $out->getRequest()['headers']['Authorization']);
        $this->assertSame('[redacted]', $out->getRequest()['data']['pin']);
        $this->assertSame(1200, $out->getRequest()['data']['amount_minor']);
        $this->assertSame('[redacted]', $out->getExtra()['phone']);
        $this->assertSame(['tenant_id' => '0199f5a2-1111-7222-8333-444455556666'], $out->getTags());
        $this->assertSame('u-1', $out->getUser()->getId());
        $this->assertNull($out->getUser()->getEmail());
        $this->assertNull($out->getUser()->getIpAddress());
        $this->assertSame('sign-in for [email]', $out->getBreadcrumbs()[0]->getMessage());
        $this->assertSame('[redacted]', $out->getBreadcrumbs()[0]->getMetadata()['token']);
    }

    public function test_sentry_is_off_without_a_dsn_and_wired_to_the_scrubber(): void
    {
        $this->assertNull(config('sentry.dsn'));
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertFalse(config('sentry.breadcrumbs.sql_bindings'));
        $this->assertSame([SentryEvents::class, 'beforeSend'], config('sentry.before_send'));
        $this->assertSame([SentryEvents::class, 'beforeBreadcrumb'], config('sentry.before_breadcrumb'));
        $this->assertNull(app('sentry')->getClient()?->getOptions()->getDsn());
    }

    public function test_every_log_channel_is_scrubbed(): void
    {
        foreach (config('logging.channels') as $name => $channel) {
            // The stack only lists other channels; null writes nothing; emergency is Laravel's last resort.
            if (! isset($channel['driver']) || $channel['driver'] === 'stack' || $name === 'null' || str_starts_with((string) $name, 'sentry')) {
                continue;
            }
            $this->assertContains(\App\Core\Observability\Logging\ScrubLogs::class, $channel['tap'] ?? [], "Log channel {$name} has no ScrubLogs tap.");
        }
    }
}
