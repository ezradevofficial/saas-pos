<?php

namespace Tests\Feature\Core\Currency;

use App\Core\Audit\AuditContext;
use App\Core\Audit\AuditEntry;
use App\Core\Currency\Feeds\BccFeed;
use App\Core\Currency\Feeds\CbkFeed;
use App\Core\Currency\Feeds\FakeRateFeed;
use App\Core\Currency\Feeds\FeedNotConfigured;
use App\Core\Currency\Feeds\RateFeeds;
use App\Core\Currency\Jobs\FetchReferenceRates;
use App\Core\Currency\Models\ExchangeRate;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CUR-03: daily reference rates from the company's feed.
class FetchReferenceRatesTest extends TestCase
{
    use BuildsExchangeRates, BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->congoCurrencies();
        $this->inTenant(fn () => $this->acme->forceFill(['rate_feed' => 'bcc'])->save());
    }

    private function runJob(string $date = '2026-10-08'): void
    {
        app(TenantContext::class)->set(null);
        (new FetchReferenceRates($this->owner->tenant_id, $this->acme->id, $date))->handle(app(RateFeeds::class), app(AuditContext::class));
    }

    private function dispatchJob(string $date = '2026-10-08'): void
    {
        app(TenantContext::class)->set(null);
        FetchReferenceRates::dispatchSync($this->owner->tenant_id, $this->acme->id, $date);
    }

    public function test_the_job_stores_the_feed_rates_as_reference_rates_once(): void
    {
        $fake = new FakeRateFeed(['CDF' => '2845.5', 'EUR' => '0.9'], 'bcc');
        app(RateFeeds::class)->swap('bcc', $fake);

        $this->dispatchJob();
        $this->dispatchJob(); // idempotent

        $this->assertSame([['base' => 'USD', 'quotes' => ['CDF'], 'date' => '2026-10-08']], [$fake->calls[0]]);

        $this->inTenant(function () {
            $rate = ExchangeRate::sole();
            $this->assertSame(['USD', 'CDF', 'reference', '2845.50000000', 'bcc', null], [$rate->base, $rate->quote, $rate->kind, $rate->mid, $rate->source, $rate->entered_by]);
            $this->assertTrue(CarbonImmutable::parse('2026-10-08 00:00:00Z')->equalTo($rate->effective_at));
            $this->assertNull(AuditEntry::where('action', 'core.exchange_rate.create')->sole()->user_id);
        });
    }

    public function test_a_feed_without_an_endpoint_is_logged_and_skipped(): void
    {
        config(['services.rate_feeds.bcc.url' => null]);
        Log::spy();

        $this->dispatchJob();

        Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'bcc rate feed has no endpoint'))->once();
        $this->inTenant(fn () => $this->assertSame(0, ExchangeRate::count()));
    }

    public function test_a_company_without_a_feed_is_left_alone(): void
    {
        $this->inTenant(fn () => $this->acme->forceFill(['rate_feed' => 'none'])->save());
        $fake = new FakeRateFeed(['CDF' => '2845.5']);
        app(RateFeeds::class)->swap('bcc', $fake);
        app(RateFeeds::class)->swap('none', $fake);

        $this->runJob();

        $this->assertSame([], $fake->calls);
    }

    public function test_the_drivers_throw_not_configured_without_a_url_and_read_the_normalised_endpoint_with_one(): void
    {
        foreach ([new CbkFeed(null), new BccFeed('')] as $feed) {
            try {
                $feed->fetch('KES', ['USD'], CarbonImmutable::parse('2026-10-08'));
                $this->fail('expected FeedNotConfigured');
            } catch (FeedNotConfigured) {
                $this->addToAssertionCount(1);
            }
        }

        Http::preventStrayRequests();
        Http::fake(['rates.example.test/*' => Http::response(['rates' => [
            ['quote' => 'USD', 'mid' => '0.00774', 'buy' => '0.0077', 'sell' => null, 'effective_at' => '2026-10-08T09:00:00Z'],
            ['quote' => 'JPY', 'mid' => '1.1'],
        ]])]);

        $rates = (new CbkFeed('https://rates.example.test/cbk'))->fetch('KES', ['USD'], CarbonImmutable::parse('2026-10-08'));

        $this->assertCount(1, $rates);
        $this->assertSame(['KES/USD', '0.00774000', '0.00770000', null, 'reference', 'cbk'], [$rates[0]->pair(), $rates[0]->mid, $rates[0]->buy, $rates[0]->sell, $rates[0]->kind, $rates[0]->source]);
        Http::assertSent(fn ($request) => $request->url() === 'https://rates.example.test/cbk?base=KES&quotes=USD&date=2026-10-08');
    }
}
