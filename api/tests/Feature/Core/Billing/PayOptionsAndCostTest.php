<?php

namespace Tests\Feature\Core\Billing;

use App\Core\Billing\Catalogue\Models\CataloguePrice;
use App\Core\Billing\Models\BillingPayment;
use App\Core\Billing\Operator\Operator;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Models\PaymentIntent;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsBilling;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * SUB-04: the pay dialog offers only what the operator can take now (the API
 * says which providers, per invoice currency); Kenya pays through Paystack
 * (M-Pesa, Airtel Money, card), each choice sending its own channels. SUB-02:
 * the monthly cost and the next invoice of a trial not subscribed yet come
 * from the same prices.
 */
class PayOptionsAndCostTest extends TestCase
{
    use BuildsBilling, RefreshTenantDatabase, ResetsPlatformTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();
    }

    private function providers(string $currency = 'KES'): array
    {
        return $this->getJson('/api/v1/subscription', $this->headersFor())->assertOk()->json("data.pay_providers.{$currency}") ?? [];
    }

    /** Real drivers as in production: Daraja and Airtel are off for billing (owner, 2026-10-28), the card is Paystack. */
    private function productionDrivers(): void
    {
        config([
            'billing.drivers.mpesa_ke' => 'mpesa_daraja',
            'billing.drivers.airtel_ke' => 'airtel_money',
            'billing.drivers.card_aggregator' => 'paystack',
            'billing.disabled_providers' => ['mpesa_ke', 'airtel_ke'],
            'payments.paystack.secret_key' => null,
        ]);
    }

    private function operatorMethod(string $provider, array $attributes): void
    {
        $this->inOperator(function () use ($provider, $attributes) {
            $method = PaymentMethod::query()->where('provider', $provider)->where('company_id', app(Operator::class)->company('KE')->id)->firstOrFail();
            $method->fill($attributes)->save();
        });
    }

    private function openInvoice(): string
    {
        $existing = $this->invoices();

        return $existing !== [] ? $existing[0]->id : $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated()->json('data.id');
    }

    public function test_only_paystack_is_offered_and_only_with_its_key_even_with_daraja_set_up(): void
    {
        $this->productionDrivers();
        $this->assertSame([], $this->providers());

        // Daraja fully set up and on: still not offered for billing (it stays available to a business's own POS).
        $this->operatorMethod('mpesa_ke', ['active' => true, 'settings' => ['shortcode' => '174379'], 'secrets' => ['consumer_key' => 'k', 'consumer_secret' => 's', 'passkey' => 'p']]);
        $this->assertSame([], $this->providers());

        config(['payments.paystack.secret_key' => 'sk_test_x']);
        $this->assertSame(['card_aggregator'], $this->providers());
        $this->getJson('/api/v1/subscription', $this->headersFor())->assertJsonPath('data.card_choices', ['mpesa', 'airtel', 'card']);

        // Daraja off for billing also refuses a direct payment.
        $invoice = $this->openInvoice();
        $this->postJson("/api/v1/subscription/invoices/{$invoice}/pay", ['provider' => 'mpesa_ke', 'phone' => '0712345678'], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'provider_unavailable');
    }

    public function test_each_kenyan_choice_sends_its_paystack_channels(): void
    {
        $this->productionDrivers();
        config(['payments.paystack.secret_key' => 'sk_test_x', 'payments.paystack.base_url' => 'https://api.paystack.co', 'payments.paystack.callback_url' => 'https://app.example.com/r']);
        $invoice = $this->openInvoice();

        foreach (['mpesa' => ['mobile_money'], 'airtel' => ['mobile_money'], 'card' => ['card']] as $channel => $channels) {
            Http::swap(new Factory);
            Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'access_code' => 'ac', 'reference' => 'x']])]);

            $this->postJson("/api/v1/subscription/invoices/{$invoice}/pay", ['provider' => 'card_aggregator', 'channel' => $channel], $this->headersFor())->assertCreated();

            Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/transaction/initialize') && $request['channels'] === $channels);
            // The next try is a new payment: the first one is over.
            $this->inTenant(fn () => BillingPayment::query()->update(['status' => 'failed']));
            $this->inOperator(fn () => PaymentIntent::query()->update(['status' => 'failed']));
        }

        $this->postJson("/api/v1/subscription/invoices/{$invoice}/pay", ['provider' => 'card_aggregator', 'channel' => 'bitcoin'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('channel');
    }

    public function test_paying_with_an_unconfigured_provider_is_refused(): void
    {
        $this->productionDrivers();
        config(['billing.disabled_providers' => []]);
        $invoice = $this->openInvoice();

        $this->postJson("/api/v1/subscription/invoices/{$invoice}/pay", ['provider' => 'airtel_ke', 'phone' => '0712345678'], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'provider_unavailable');
    }

    public function test_the_fake_drivers_of_local_work_offer_everything(): void
    {
        $this->assertSame(['card_aggregator', 'mpesa_ke', 'airtel_ke'], $this->providers());
    }

    public function test_monthly_cost_and_next_invoice_agree_when_the_catalogue_price_changed_after_the_trial_started(): void
    {
        $before = $this->getJson('/api/v1/subscription', $this->headersFor())->assertOk();
        $this->assertSame(390000, $before->json('data.monthly_amount.amount_minor'));
        $this->assertSame(390000, $before->json('data.next_invoice.amount.amount_minor'));

        // The platform raises the Starter price after the trial began (SUB-02: a period start is priced at that day's catalogue).
        CataloguePrice::query()->create(['item_type' => 'bundle', 'item_key' => 'starter', 'country' => 'KE', 'currency' => 'KES', 'amount' => 500000, 'effective_from' => '2026-11-02']);
        $this->travelToDay('2026-11-03 08:00:00');

        $after = $this->getJson('/api/v1/subscription', $this->headersFor())->assertOk();
        $this->assertSame(500000, $after->json('data.next_invoice.amount.amount_minor'));
        $this->assertSame(500000, $after->json('data.monthly_amount.amount_minor'));

        // Subscribing issues exactly what was shown.
        $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated()->assertJsonPath('data.total.amount_minor', 500000);
        $subscribed = $this->getJson('/api/v1/subscription', $this->headersFor())->assertOk();
        $this->assertSame(500000, $subscribed->json('data.monthly_amount.amount_minor'));
        $this->assertSame(500000, $subscribed->json('data.next_invoice.amount.amount_minor'));
    }
}
