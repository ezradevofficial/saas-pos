<?php

namespace Tests\Feature\Core\Payments;

use App\Core\Payments\Models\PaymentIntent;
use App\Core\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Payment callbacks are allowed by the caller's address: only the load
// balancer's backend range (TRUSTED_PROXIES) may report the client's
// address in X-Forwarded-For; anyone else's header is ignored.
class TrustedProxiesTest extends TestCase
{
    use BuildsPayments, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPayments();
        $this->fakeDaraja();
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    private function callbackVia(string $remote, string $forwarded, string $checkout)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => $forwarded])
            ->postJson('/api/v1/payments/callbacks/'.$this->callbackToken().'/stk', $this->stkCallback($checkout, 0));
    }

    public function test_a_spoofed_forwarded_address_is_ignored_and_the_load_balancers_is_used(): void
    {
        $intent = $this->inTenant(fn () => PaymentIntent::query()->findOrFail($this->push()->assertCreated()->json('data.id')));

        // Nothing trusted (the default): the header is ignored, the caller is the remote.
        $this->callbackVia('203.0.113.9', self::SAFARICOM_IP, $intent->provider_checkout_id)->assertForbidden();

        // The NodeBalancer's backend range is trusted: its X-Forwarded-For names the client.
        TrustProxies::at(TrustedProxies::parse('192.168.255.0/24'));
        TrustProxies::withHeaders(TrustedProxies::HEADERS);
        $this->callbackVia('203.0.113.9', self::SAFARICOM_IP, $intent->provider_checkout_id)->assertForbidden();
        $this->callbackVia('192.168.255.14', '203.0.113.9', $intent->provider_checkout_id)->assertForbidden();
        $this->callbackVia('192.168.255.14', self::SAFARICOM_IP, $intent->provider_checkout_id)->assertOk();
        $this->assertSame('succeeded', $this->inTenant(fn () => $intent->fresh()->status));
    }

    public function test_the_setting_never_trusts_everyone(): void
    {
        $this->assertSame([], TrustedProxies::parse(null));
        $this->assertSame([], TrustedProxies::parse('*, **'));
        $this->assertSame(['192.168.255.0/24', '10.0.0.1'], TrustedProxies::parse(' 192.168.255.0/24 ,10.0.0.1,'));
    }
}
