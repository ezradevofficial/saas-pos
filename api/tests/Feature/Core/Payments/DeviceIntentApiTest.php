<?php

namespace Tests\Feature\Core\Payments;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\PaymentMethods\DefaultPaymentMethods;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Concept note 7.1, TEN-05: the till's payment intents. Scoped to the
// device: the method must be its company's, a poll finds only intents of
// its own location, and nothing crosses tenants. Idempotent by the id the
// till gives; manual codes are refused when already used.
class DeviceIntentApiTest extends TestCase
{
    use BuildsPayments, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPayments();
        $this->fakeDaraja();
    }

    public function test_the_same_id_answers_the_same_intent_and_pushes_once(): void
    {
        $id = (string) Str::uuid7();

        $first = $this->push(extra: ['id' => $id])->assertCreated()->assertJsonPath('data.id', $id);
        $again = $this->push(extra: ['id' => $id])->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame($first->json('data.account_reference'), $again->json('data.account_reference'));
        Http::assertSentCount(2); // one token, one push
        $this->assertSame(1, $this->inTenant(fn () => PaymentIntent::query()->count()));
    }

    public function test_the_same_id_with_other_content_is_a_conflict(): void
    {
        $id = (string) Str::uuid7();
        $this->push(extra: ['id' => $id])->assertCreated();

        $this->push('200000', extra: ['id' => $id])->assertStatus(409)->assertJsonPath('code', 'id_conflict');
        $this->push(phone: '0712999999', extra: ['id' => $id])->assertStatus(409);
    }

    public function test_pushes_are_rate_limited_per_device_and_per_phone(): void
    {
        foreach (range(1, 3) as $n) {
            $this->push(extra: ['reference' => (string) Str::uuid7()])->assertCreated();
        }
        $this->push(extra: ['reference' => (string) Str::uuid7()])->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

        // Other phones go on until the device's own limit.
        foreach (range(4, 10) as $n) {
            $this->push(phone: sprintf('07120000%02d', $n), extra: ['reference' => (string) Str::uuid7()])->assertCreated();
        }
        $this->push(phone: '0712000099', extra: ['reference' => (string) Str::uuid7()])->assertStatus(429);
    }

    public function test_only_sales_and_a_staff_cashier_of_the_location(): void
    {
        $this->push(extra: ['reference_type' => 'pos.refund'])->assertUnprocessable()->assertJsonValidationErrors('reference_type');
        $this->push(extra: ['user_id' => null])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        // A cashier of Outlet B is not staff at Outlet A's till.
        $elsewhere = $this->userWith('cashier', Scope::location($this->locationB->id));
        $this->push(extra: ['user_id' => $elsewhere->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $here = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->push(extra: ['user_id' => $here->id])->assertCreated();
    }

    public function test_a_poll_finds_only_intents_of_the_devices_location(): void
    {
        $id = $this->push()->assertCreated()->json('data.id');

        $this->getJson("/api/v1/payments/intents/{$id}", $this->tillHeaders())->assertOk()->assertJsonPath('data.status', 'pending');

        // Another till of the same company, at Outlet B.
        [, $tokenB] = $this->inTenant(fn () => $this->pairedTill($this->locationB, 'Till B'));
        $this->getJson("/api/v1/payments/intents/{$id}", $this->tillHeaders($tokenB))->assertNotFound();

        // A till of another tenant.
        $other = $this->otherTenant();
        [, $foreign] = $this->asTenant($other['user']->tenant_id, fn () => $this->pairedTill($other['location'], 'Foreign till'));
        $response = $this->getJson("/api/v1/payments/intents/{$id}", $this->tillHeaders($foreign))->assertNotFound();
        $this->assertStringNotContainsString($id, $response->getContent());

        // People's tokens never reach device routes.
        $this->getJson("/api/v1/payments/intents/{$id}", [...$this->headersFor(), 'Accept' => 'application/json'])->assertForbidden();
    }

    public function test_only_an_active_method_of_the_devices_company_is_taken(): void
    {
        $other = $this->otherTenant();
        $foreignMethod = $this->asTenant($other['user']->tenant_id, function () use ($other) {
            app(TenantCurrencies::class)->provisionFor($other['company']);
            DB::connection(TenantContext::CONNECTION)->transaction(fn () => app(DefaultPaymentMethods::class)->seed($other['company']));

            return PaymentMethod::query()->where('provider', 'mpesa_ke')->sole()->id;
        });

        $this->push(extra: ['payment_method_id' => $foreignMethod])->assertUnprocessable()->assertJsonValidationErrors('payment_method_id');

        $sister = $this->inTenant(function () {
            $company = $this->company('Sister');
            app(TenantCurrencies::class)->provisionFor($company);
            DB::connection(TenantContext::CONNECTION)->transaction(fn () => app(DefaultPaymentMethods::class)->seed($company));

            return PaymentMethod::query()->where('company_id', $company->id)->where('type', 'cash')->first()->id;
        });
        $this->push(extra: ['payment_method_id' => $sister])->assertUnprocessable()->assertJsonValidationErrors('payment_method_id');

        $this->inTenant(fn () => $this->mpesa->fill(['active' => false])->save());
        $this->push()->assertUnprocessable()->assertJsonValidationErrors('payment_method_id');
    }

    public function test_a_manual_code_is_recorded_unverified_and_used_only_once(): void
    {
        $manual = fn (string $sale, string $code = 'qjk3abc123') => $this->postJson('/api/v1/payments/intents', [
            'payment_method_id' => $this->mpesa->id,
            'mode' => 'manual',
            'amount_minor' => '150000',
            'currency' => 'KES',
            'receipt' => $code,
            'reference_type' => 'pos.sale',
            'reference' => $sale,
            'user_id' => $this->owner->id,
        ], $this->tillHeaders());

        $manual((string) Str::uuid7())->assertCreated()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.verification', 'unverified')
            ->assertJsonPath('data.receipt', 'QJK3ABC123')
            ->assertJsonPath('data.mode', 'manual');

        $manual((string) Str::uuid7())->assertUnprocessable()->assertJsonPath('code', 'receipt_used');
        $manual((string) Str::uuid7(), 'bad code!')->assertUnprocessable()->assertJsonValidationErrors('receipt');
        Http::assertNothingSent();
    }

    public function test_the_intent_and_its_audit_entries_never_hold_the_phone_number(): void
    {
        $id = $this->push(phone: '0712 999 888')->assertCreated()->json('data.id');

        $this->inTenant(function () use ($id) {
            $entries = AuditEntry::query()->where('auditable_id', $id)->get();
            $this->assertNotEmpty($entries);
            $this->assertStringNotContainsString('254712999888', $entries->toJson());

            $raw = DB::table('payment_intents')->where('id', $id)->value('phone');
            $this->assertStringNotContainsString('254712999888', (string) $raw);
            $this->assertSame('254712999888', PaymentIntent::query()->findOrFail($id)->phone);
        });
    }

    public function test_the_cash_driver_and_the_fake_driver_override(): void
    {
        config(['payments.drivers' => ['mpesa_ke' => 'fake']]);

        $failed = $this->push(phone: '0712345000')->assertCreated();
        $failed->assertJsonPath('data.status', 'failed');

        $pending = $this->push(extra: ['reference' => (string) Str::uuid7()])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertStringStartsWith('fake-', $this->inTenant(fn () => PaymentIntent::query()->findOrFail($pending->json('data.id'))->provider_checkout_id));

        config(['payments.allow_fake' => false]);
        $this->expectException(\RuntimeException::class);
        $this->withoutExceptionHandling()->push(extra: ['reference' => (string) Str::uuid7()]);
    }
}
