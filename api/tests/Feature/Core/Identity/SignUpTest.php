<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Currency\Models\CompanyCurrency;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Notifications\Channels\SmsChannel;
use App\Core\Notifications\Sms\LogSmsSender;
use App\Core\Notifications\Sms\SmsSender;
use App\Core\Tenancy\Events\TenantProvisioned;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-01: self sign-up with email or phone, verified by a one-time code.
class SignUpTest extends TestCase
{
    use CreatesIdentities, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function signUp(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/sign-up', array_merge([
            'name' => 'Amina Otieno',
            'email' => 'amina@example.com',
            'password' => $this->password,
            'country' => 'KE',
            'locale' => 'en',
            'business_name' => 'Amina Stores',
        ], $overrides));
    }

    private function enterChallengeTenant(string $challengeId): string
    {
        $tenantId = VerificationChallenge::findOrFail($challengeId)->tenant_id;
        app(TenantContext::class)->set($tenantId);

        return $tenantId;
    }

    private function wrongCode(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    public function test_sign_up_with_email_creates_one_tenant_hierarchy_and_a_pending_owner(): void
    {
        Event::fake([TenantProvisioned::class]);

        $response = $this->signUp()->assertCreated()->assertJsonStructure(['challenge_id', 'destination_masked']);

        $tenantId = $this->enterChallengeTenant($response->json('challenge_id'));

        $this->assertSame(1, Tenant::count());
        $tenant = Tenant::sole();
        $this->assertSame($tenantId, $tenant->id);
        $this->assertSame('Amina Stores', $tenant->name);
        $this->assertSame('en', $tenant->default_locale);

        $company = Company::sole();
        $this->assertSame(['Amina Stores', 'KE', 'KES', 1, 'Africa/Nairobi'], [
            $company->name, $company->country, $company->base_currency, $company->fiscal_year_start_month, $company->timezone,
        ]);
        $this->assertSame('Main branch', Branch::sole()->name);
        // CUR-01: a Kenyan company's tenant uses KES and USD.
        $this->assertSame(
            [['KES', 2, 1, true], ['USD', 2, 1, true]],
            TenantCurrency::orderBy('code')->get()->map(fn ($c) => [$c->code, $c->decimals, $c->cash_rounding_minor, $c->active])->all(),
        );
        $this->assertSame(0, CompanyCurrency::count(), 'no reporting currencies by default');
        $location = Location::sole();
        $this->assertSame(['Main outlet', 'outlet'], [$location->name, $location->type]);

        $owner = User::sole();
        $this->assertSame('pending', $owner->status);
        $this->assertSame('amina@example.com', $owner->email);
        $this->assertNull($owner->email_verified_at);

        Event::assertDispatched(TenantProvisioned::class, fn (TenantProvisioned $e) => $e->tenant->is($tenant) && $e->owner->is($owner));

        Notification::assertSentTo($owner, VerificationCode::class, fn (VerificationCode $n, array $channels) => $channels === ['mail']
            && preg_match('/^\d{6}$/', $n->code) === 1);

        $this->assertNotSame('amina@example.com', $response->json('destination_masked'));
        $this->assertStringEndsWith('@example.com', $response->json('destination_masked'));
    }

    public function test_democratic_republic_of_congo_companies_use_usd_and_kinshasa(): void
    {
        $response = $this->signUp(['country' => 'CD'])->assertCreated();
        $this->enterChallengeTenant($response->json('challenge_id'));

        $company = Company::sole();
        $this->assertSame(['CD', 'USD', 'Africa/Kinshasa'], [$company->country, $company->base_currency, $company->timezone]);

        // CUR-01: USD and CDF, CDF with 0 decimals and cash rounding to 50.
        $this->assertSame(
            [['CDF', 0, 50, true], ['USD', 2, 1, true]],
            TenantCurrency::orderBy('code')->get()->map(fn ($c) => [$c->code, $c->decimals, $c->cash_rounding_minor, $c->active])->all(),
        );
    }

    public function test_the_sign_up_company_gets_its_country_pack_tax_codes(): void
    {
        foreach (['KE' => ['VAT_EXEMPT', 'VAT_STD', 'VAT_WHT', 'VAT_ZERO'], 'CD' => ['VAT_EXEMPT', 'VAT_STD', 'VAT_ZERO']] as $country => $codes) {
            $response = $this->signUp(['country' => $country, 'email' => strtolower($country).'@example.com'])->assertCreated();
            $this->enterChallengeTenant($response->json('challenge_id'));

            // CP-01: copied in the sign-up transaction, unconfirmed rates still needed.
            $company = Company::sole();
            $this->assertSame($codes, TaxCode::where('company_id', $company->id)->orderBy('code')->pluck('code')->all());
            $std = TaxCode::where('code', 'VAT_STD')->sole()->rates()->sole();
            $this->assertSame([null, true], [$std->rate, $std->needs_confirmation]);

            app(TenantContext::class)->set(null);
        }
    }

    public function test_the_tenant_gets_the_default_units_named_in_english_and_french(): void
    {
        // MD-02: seeded in the sign-up transaction, whatever the sign-up language.
        $this->enterChallengeTenant($this->signUp(['locale' => 'fr'])->assertCreated()->json('challenge_id'));

        $units = Uom::query()->orderBy('code')->get()->keyBy(fn (Uom $uom) => strtoupper($uom->code));
        $this->assertSame(['BOX', 'EA', 'G', 'KG', 'L', 'M', 'ML', 'PACK'], $units->keys()->all());
        $this->assertSame(['Each', 'Pièce', 'count'], [$units['EA']->name_en, $units['EA']->name_fr, $units['EA']->kind]);
        $this->assertSame(['Kilogram', 'Kilogramme', 'weight'], [$units['KG']->name_en, $units['KG']->name_fr, $units['KG']->kind]);
        $this->assertSame(['Millilitre', 'volume'], [$units['ML']->name_fr, $units['ML']->kind]);
    }

    public function test_the_right_code_activates_the_owner_and_returns_a_working_token(): void
    {
        $challengeId = $this->signUp()->assertCreated()->json('challenge_id');

        $response = $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challengeId, 'code' => $this->lastCode()])
            ->assertOk()
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.email', 'amina@example.com');

        $this->getJson('/api/v1/me', $this->bearer($response->json('token')))
            ->assertOk()
            ->assertJsonPath('data.email', 'amina@example.com');

        $this->enterChallengeTenant($challengeId);
        $this->assertNotNull(User::sole()->email_verified_at);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $challengeId = $this->signUp()->assertCreated()->json('challenge_id');
        $code = $this->lastCode();

        $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challengeId, 'code' => $code])->assertOk();
        $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challengeId, 'code' => $code])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    }

    public function test_five_wrong_codes_exhaust_the_challenge(): void
    {
        $challengeId = $this->signUp()->assertCreated()->json('challenge_id');
        $code = $this->lastCode();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challengeId, 'code' => $this->wrongCode($code)])
                ->assertStatus(422)
                ->assertJsonPath('code', 'invalid_code');
        }

        $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challengeId, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('message', __('auth.code.attempts'));

        $this->enterChallengeTenant($challengeId);
        $this->assertSame('pending', User::sole()->status);
    }

    public function test_an_expired_code_fails(): void
    {
        $challengeId = $this->signUp()->assertCreated()->json('challenge_id');
        $code = $this->lastCode();

        $this->travel(31)->minutes();

        $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challengeId, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('message', __('auth.code.expired'));
    }

    public function test_an_unknown_challenge_fails_cleanly(): void
    {
        $this->postJson('/api/v1/auth/verify', ['challenge_id' => '0190a0a0-0000-7000-8000-000000000000', 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_code');
    }

    public function test_resend_replaces_the_challenge(): void
    {
        $first = $this->signUp()->assertCreated()->json('challenge_id');
        $firstCode = $this->lastCode();

        $this->travel(61)->seconds();

        $second = $this->postJson('/api/v1/auth/verify/resend', ['challenge_id' => $first])
            ->assertOk()
            ->assertJsonStructure(['challenge_id', 'destination_masked'])
            ->json('challenge_id');
        $this->assertNotSame($first, $second);

        $this->postJson('/api/v1/auth/verify', ['challenge_id' => $first, 'code' => $firstCode])->assertStatus(422);
        $this->postJson('/api/v1/auth/verify', ['challenge_id' => $second, 'code' => $this->lastCode()])->assertOk();
    }

    public function test_a_duplicate_email_is_rejected_whatever_the_case(): void
    {
        $this->signUp()->assertCreated();

        app(TenantContext::class)->set(null);

        $this->signUp(['email' => 'AMINA@example.com', 'business_name' => 'Other'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.email.0', __('validation.unique', ['attribute' => 'email']));
    }

    public function test_a_duplicate_phone_is_rejected_across_formats(): void
    {
        $this->signUp(['email' => null, 'phone' => '+254712345678'])->assertCreated();

        $this->signUp(['email' => null, 'phone' => '0712 345 678', 'business_name' => 'Other'])
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', __('validation.unique', ['attribute' => 'phone']));
    }

    public function test_email_or_phone_is_required(): void
    {
        $this->signUp(['email' => null])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['email', 'phone']);
    }

    public function test_phone_sign_up_sends_the_code_by_sms_in_e164(): void
    {
        $response = $this->signUp(['email' => null, 'phone' => '0712345678'])->assertCreated();
        $this->enterChallengeTenant($response->json('challenge_id'));

        $owner = User::sole();
        $this->assertSame('+254712345678', $owner->phone);
        Notification::assertSentTo($owner, VerificationCode::class, fn ($n, array $channels) => $channels === [SmsChannel::class]);
        $this->assertStringEndsWith('78', $response->json('destination_masked'));
        $this->assertStringNotContainsString('712345', $response->json('destination_masked'));
    }

    public function test_congolese_local_numbers_get_the_243_prefix(): void
    {
        $response = $this->signUp(['email' => null, 'phone' => '0812345678', 'country' => 'CD'])->assertCreated();
        $this->enterChallengeTenant($response->json('challenge_id'));

        $this->assertSame('+243812345678', User::sole()->phone);
    }

    public function test_an_invalid_phone_is_rejected(): void
    {
        $this->signUp(['email' => null, 'phone' => '12ab'])->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    public function test_the_sms_channel_sends_through_the_sms_sender(): void
    {
        Notification::swap(new ChannelManager($this->app));

        $sender = new class implements SmsSender
        {
            public array $sent = [];

            public function send(string $to, string $message): void
            {
                $this->sent[] = [$to, $message];
            }
        };
        $this->app->instance(SmsSender::class, $sender);

        $this->signUp(['email' => null, 'phone' => '+254712345678'])->assertCreated();

        $this->assertCount(1, $sender->sent);
        $this->assertSame('+254712345678', $sender->sent[0][0]);
        $this->assertMatchesRegularExpression('/\b\d{6}\b/', $sender->sent[0][1]);
    }

    public function test_the_log_sms_sender_writes_to_the_log(): void
    {
        Log::shouldReceive('info')->once()->withArgs(fn ($message, $context) => $context['to'] === '+254712345678');

        (new LogSmsSender)->send('+254712345678', 'Your code is 123456');
    }

    public function test_french_sign_up_names_the_defaults_in_french(): void
    {
        $response = $this->signUp(['locale' => 'fr'])->assertCreated();
        $this->enterChallengeTenant($response->json('challenge_id'));

        $this->assertSame('fr', Tenant::sole()->default_locale);
        $this->assertSame('fr', User::sole()->locale);
        $this->assertSame('Succursale principale', Branch::sole()->name);
        $this->assertSame('Point de vente principal', Location::sole()->name);
    }

    public function test_a_common_password_is_rejected_at_sign_up(): void
    {
        $this->signUp(['password' => 'password123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', __('auth.password.common'));
    }
}
