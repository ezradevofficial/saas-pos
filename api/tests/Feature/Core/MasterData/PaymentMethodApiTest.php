<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\PaymentMethods\DefaultPaymentMethods;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MD-04: payment methods per company, seeded by country, in till order;
// provider credentials stored encrypted and never returned, logged or
// audited; switched on only once configured.
class PaymentMethodApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private const SECRET = 'zq-known-secret-7f3a9c';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->inTenant(function () {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            DB::connection(TenantContext::CONNECTION)->transaction(fn () => app(DefaultPaymentMethods::class)->seed($this->acme));
        });
    }

    private function methods(?string $companyId = null, string $query = ''): array
    {
        return $this->getJson('/api/v1/companies/'.($companyId ?? $this->acme->id).'/payment-methods'.$query, $this->headersFor())->assertOk()->json('data');
    }

    private function idOf(string $key): string
    {
        return collect($this->methods())->first(fn (array $m) => ($m['provider'] ?? 'cash:'.$m['currency']) === $key)['id'];
    }

    private function mpesaConfig(): array
    {
        return [
            'settings' => ['shortcode' => '174379'],
            'secrets' => ['consumer_key' => 'ck-'.self::SECRET, 'consumer_secret' => 'cs-'.self::SECRET, 'passkey' => 'pk-'.self::SECRET],
        ];
    }

    public function test_new_companies_get_their_countrys_payment_methods(): void
    {
        $ke = $this->postJson('/api/v1/companies', ['name' => 'Nairobi Ltd', 'country' => 'KE'], $this->headersFor())->assertCreated()->json('data.id');
        $cd = $this->postJson('/api/v1/companies', ['name' => 'Kinshasa SARL', 'country' => 'CD'], $this->headersFor())->assertCreated()->json('data.id');

        $summary = fn (string $company) => array_map(
            fn (array $m) => [$m['type'], $m['provider'] ?? $m['currency'], $m['active'], $m['position']],
            $this->methods($company),
        );

        $this->assertSame([
            ['cash', 'KES', true, 1],
            ['cash', 'USD', true, 2],
            ['mobile_money', 'mpesa_ke', false, 3],
            ['mobile_money', 'airtel_ke', false, 4],
            ['card', 'card_aggregator', false, 5],
        ], $summary($ke));

        $this->assertSame([
            ['cash', 'USD', true, 1],
            ['cash', 'CDF', true, 2],
            ['mobile_money', 'vodacom_mpesa_cd', false, 3],
            ['mobile_money', 'orange_money_cd', false, 4],
            ['mobile_money', 'airtel_money_cd', false, 5],
            ['mobile_money', 'afrimoney_cd', false, 6],
            ['card', 'card_aggregator', false, 7],
        ], $summary($cd));

        $cdf = collect($this->methods($cd))->firstWhere('currency', 'CDF');
        $this->assertSame(['Cash CDF', 'Espèces CDF'], [$cdf['name_en'], $cdf['name_fr']]);
        $vodacom = collect($this->methods($cd))->firstWhere('provider', 'vodacom_mpesa_cd');
        $this->assertSame('M-Pesa Vodacom', $vodacom['name']);
        $this->assertFalse($vodacom['configured']);
        $this->assertSame(['merchant_id', 'api_key'], $vodacom['missing']);
    }

    public function test_seeding_again_never_recreates_an_entry_the_company_ever_had(): void
    {
        $this->postJson("/api/v1/payment-methods/{$this->idOf('mpesa_ke')}/archive", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/payment-methods/{$this->idOf('cash:USD')}/archive", [], $this->headersFor())->assertOk();

        $created = $this->inTenant(fn () => DB::connection(TenantContext::CONNECTION)->transaction(fn () => app(DefaultPaymentMethods::class)->seed($this->acme)));

        $this->assertSame(0, $created);
        $this->inTenant(fn () => $this->assertSame(5, PaymentMethod::query()->where('company_id', $this->acme->id)->count()));
    }

    public function test_cash_needs_a_currency_active_in_the_tenant(): void
    {
        $url = "/api/v1/companies/{$this->acme->id}/payment-methods";
        $names = ['name_en' => 'Till float', 'name_fr' => 'Fonds de caisse'];

        $this->postJson($url, ['type' => 'cash', ...$names], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->postJson($url, ['type' => 'cash', 'currency' => 'EUR', ...$names], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('currency');

        $id = $this->postJson($url, ['type' => 'cash', 'currency' => 'KES', 'active' => true, ...$names], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.active', true)->assertJsonPath('data.position', 6)->json('data.id');

        // The currency can't be removed from a cash method.
        $this->patchJson("/api/v1/payment-methods/{$id}", ['currency' => null], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('currency');

        // Switching a cash method on again re-checks that its currency is still active.
        $this->patchJson("/api/v1/payment-methods/{$id}", ['active' => false], $this->headersFor())->assertOk();
        $this->inTenant(fn () => DB::table('tenant_currencies')->where('code', 'KES')->update(['active' => false]));
        $this->patchJson("/api/v1/payment-methods/{$id}", ['active' => true], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'currency_not_active');
    }

    public function test_type_provider_and_config_keys_are_checked(): void
    {
        $url = "/api/v1/companies/{$this->acme->id}/payment-methods";
        $names = ['name_en' => 'X', 'name_fr' => 'X'];

        $this->postJson($url, ['type' => 'mobile_money', ...$names], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('provider');
        $this->postJson($url, ['type' => 'cash', 'currency' => 'KES', 'provider' => 'mpesa_ke', ...$names], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('provider');
        $this->postJson($url, ['type' => 'card', 'provider' => 'mpesa_ke', ...$names], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('provider');
        $this->postJson($url, ['type' => 'cheque', ...$names], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson($url, ['type' => 'voucher', 'settings' => ['x' => 'y'], ...$names], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('settings');
        $this->postJson($url, ['type' => 'voucher', ...$names], $this->headersFor())->assertCreated()->assertJsonPath('data.configured', true);

        $mpesa = $this->idOf('mpesa_ke');
        $this->patchJson("/api/v1/payment-methods/{$mpesa}", ['settings' => ['consumer_secret' => self::SECRET]], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('settings');
        $this->patchJson("/api/v1/payment-methods/{$mpesa}", ['secrets' => ['api_key' => self::SECRET]], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('secrets');
        $this->patchJson("/api/v1/payment-methods/{$mpesa}", ['type' => 'card'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->patchJson("/api/v1/payment-methods/{$mpesa}", ['provider' => 'airtel_ke'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('provider');
        // Repeating them unchanged is fine.
        $this->patchJson("/api/v1/payment-methods/{$mpesa}", ['type' => 'mobile_money', 'provider' => 'mpesa_ke', 'name_en' => 'Lipa na M-Pesa'], $this->headersFor())->assertOk();
    }

    public function test_a_provider_method_is_switched_on_only_once_configured(): void
    {
        $mpesa = $this->idOf('mpesa_ke');

        $this->patchJson("/api/v1/payment-methods/{$mpesa}", ['active' => true], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'provider_not_configured');
        $this->patchJson("/api/v1/payment-methods/{$mpesa}", ['active' => true, 'settings' => ['shortcode' => '174379']], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'provider_not_configured');
        $this->inTenant(fn () => $this->assertSame([], PaymentMethod::findOrFail($this->idOf('mpesa_ke'))->settings));

        $this->patchJson("/api/v1/payment-methods/{$mpesa}", [...$this->mpesaConfig(), 'active' => true], $this->headersFor())
            ->assertOk()->assertJsonPath('data.active', true)->assertJsonPath('data.configured', true)->assertJsonPath('data.missing', [])
            // The provider's key names (never values), so a settings form knows which fields are secret.
            ->assertJsonPath('data.setting_keys', ['shortcode'])->assertJsonPath('data.secret_keys', ['consumer_key', 'consumer_secret', 'passkey']);

        // Clearing a credential of a method that is on is refused; switched off first, it is cleared.
        $this->patchJson("/api/v1/payment-methods/{$mpesa}", ['secrets' => ['passkey' => null]], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'provider_not_configured');
        $this->patchJson("/api/v1/payment-methods/{$mpesa}", ['active' => false, 'secrets' => ['passkey' => null]], $this->headersFor())
            ->assertOk()->assertJsonPath('data.secrets_set', ['consumer_key' => true, 'consumer_secret' => true])->assertJsonPath('data.missing', ['passkey']);

        // A card method cannot be created switched on without its provider settings.
        $this->postJson("/api/v1/companies/{$this->acme->id}/payment-methods", [
            'type' => 'card', 'provider' => 'card_aggregator', 'name_en' => 'Visa', 'name_fr' => 'Visa', 'active' => true,
        ], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'provider_not_configured');
    }

    public function test_secrets_are_never_returned_logged_or_written_to_the_audit_log(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $mpesa = $this->idOf('mpesa_ke');
        $responses = [];
        $responses[] = $this->patchJson("/api/v1/payment-methods/{$mpesa}", [...$this->mpesaConfig(), 'active' => true], $this->headersFor())->assertOk();
        $responses[] = $this->postJson("/api/v1/companies/{$this->acme->id}/payment-methods", [
            'type' => 'card', 'provider' => 'card_aggregator', 'name_en' => 'Visa', 'name_fr' => 'Visa',
            'settings' => ['merchant_id' => 'M-1'], 'secrets' => ['api_key' => 'ak-'.self::SECRET], 'active' => true,
        ], $this->headersFor())->assertCreated();
        // A refused change echoes nothing back either.
        $responses[] = $this->patchJson("/api/v1/payment-methods/{$mpesa}", ['secrets' => ['passkey' => str_repeat('x', 1001).self::SECRET]], $this->headersFor())->assertUnprocessable();
        $responses[] = $this->getJson("/api/v1/payment-methods/{$mpesa}", $this->headersFor())->assertOk();
        $responses[] = $this->getJson("/api/v1/companies/{$this->acme->id}/payment-methods?status=all", $this->headersFor())->assertOk();
        $responses[] = $this->getJson("/api/v1/history/payment_method/{$mpesa}", $this->headersFor())->assertOk();

        foreach ($responses as $response) {
            $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());
        }

        $responses[3]->assertJsonPath('data.secrets_set', ['consumer_key' => true, 'consumer_secret' => true, 'passkey' => true])
            ->assertJsonPath('data.settings', ['shortcode' => '174379'])
            ->assertJsonMissingPath('data.secrets');

        $this->inTenant(function () use ($mpesa) {
            // Encrypted at rest; readable by the application only.
            $raw = DB::table('payment_methods')->where('id', $mpesa)->value('secrets');
            $this->assertStringNotContainsString(self::SECRET, $raw);
            $this->assertSame('pk-'.self::SECRET, PaymentMethod::findOrFail($mpesa)->secrets['passkey']);

            $audit = DB::table('audit_logs')->get()->map(fn ($row) => json_encode($row))->implode("\n");
            $this->assertStringNotContainsString(self::SECRET, $audit);

            // The change itself is audited by key name.
            $marker = AuditEntry::where('action', 'core.payment_method.secrets_change')->where('auditable_id', $mpesa)->sole();
            $this->assertSame(['secrets_changed' => ['consumer_key', 'consumer_secret', 'passkey']], $marker->after);
        });

        $this->assertStringNotContainsString(self::SECRET, implode("\n", $logged));

        // Clearing a key is audited the same way.
        $this->patchJson("/api/v1/payment-methods/{$mpesa}", ['active' => false, 'secrets' => ['passkey' => null]], $this->headersFor())->assertOk();
        $this->getJson("/api/v1/history/payment_method/{$mpesa}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.action', 'core.payment_method.secrets_change')
            ->assertJsonPath('data.0.after', ['secrets_changed' => ['passkey']]);
    }

    public function test_the_till_order_lists_every_active_method_once(): void
    {
        $ids = array_column($this->methods(), 'id');
        $reversed = array_reverse($ids);
        $url = "/api/v1/companies/{$this->acme->id}/payment-methods/order";

        $this->putJson($url, ['ids' => $reversed], $this->headersFor())->assertOk()->assertJsonPath('data.0.id', $reversed[0]);
        $this->assertSame($reversed, array_column($this->methods(), 'id'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($this->methods(), 'position'));

        $this->putJson($url, ['ids' => array_slice($ids, 1)], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'payment_method_order_invalid');

        $other = $this->otherTenant();
        $foreign = $this->asTenant($other['user']->tenant_id, function () use ($other) {
            app(TenantCurrencies::class)->provisionFor($other['company']);
            app(DefaultPaymentMethods::class)->seed($other['company']);

            return PaymentMethod::query()->value('id');
        });
        $this->putJson($url, ['ids' => [...array_slice($reversed, 1), $foreign]], $this->headersFor())->assertUnprocessable();

        // Archived methods leave the order; a restored one comes back last.
        $this->postJson("/api/v1/payment-methods/{$reversed[0]}/archive", [], $this->headersFor())->assertOk();
        $this->putJson($url, ['ids' => $ids], $this->headersFor())->assertUnprocessable();
        $this->putJson($url, ['ids' => array_slice($ids, 0, 4)], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/payment-methods/{$reversed[0]}/restore", [], $this->headersFor())->assertOk()->assertJsonPath('data.position', 6);
        $this->assertSame($reversed[0], array_column($this->methods(), 'id')[4]);
    }

    public function test_scope_and_permissions(): void
    {
        // Built before any request signs in (the permission guard follows the request's).
        $creator = $this->inTenant(function () {
            $role = $this->role('Method creator', ['core.company.view', 'core.payment_method.create']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::company($this->acme->id));

            return $user;
        });
        $id = $this->idOf('cash:KES');
        $create = fn (array $headers) => $this->postJson("/api/v1/companies/{$this->acme->id}/payment-methods", ['type' => 'credit', 'name_en' => 'Account', 'name_fr' => 'Compte'], $headers);

        // Tills read the methods of their company (from a location beneath it), nothing more.
        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->getJson("/api/v1/companies/{$this->acme->id}/payment-methods", $cashier)->assertOk()->assertJsonCount(5, 'data');
        $this->getJson("/api/v1/payment-methods/{$id}", $cashier)->assertOk();
        $create($cashier)->assertForbidden();
        $this->patchJson("/api/v1/payment-methods/{$id}", ['name_en' => 'Mine'], $cashier)->assertForbidden();

        $manager = $this->headersFor($this->userWith('branch_manager', Scope::branch($this->branchA->id)));
        $this->getJson("/api/v1/payment-methods/{$id}", $manager)->assertOk();
        $this->postJson("/api/v1/payment-methods/{$id}/archive", [], $manager)->assertForbidden();

        // The accountant reads them at the company; the admin manages them.
        $accountant = $this->headersFor($this->userWith('accountant', Scope::company($this->acme->id)));
        $this->getJson("/api/v1/payment-methods/{$id}", $accountant)->assertOk();
        $create($accountant)->assertForbidden();
        $this->patchJson("/api/v1/payment-methods/{$id}", ['name_en' => 'Cash shillings'], $accountant)->assertForbidden();
        $this->postJson("/api/v1/payment-methods/{$id}/archive", [], $accountant)->assertForbidden();

        $admin = $this->headersFor($this->userWith('admin', Scope::company($this->acme->id)));
        $create($admin)->assertCreated();
        $this->patchJson("/api/v1/payment-methods/{$id}", ['name_en' => 'Cash shillings'], $admin)->assertOk();
        $this->postJson("/api/v1/payment-methods/{$id}/archive", [], $admin)->assertOk();

        // Each action needs its own permission.
        $creator = $this->headersFor($creator);
        $create($creator)->assertCreated();
        $this->getJson("/api/v1/payment-methods/{$id}", $creator)->assertOk();
        $this->patchJson("/api/v1/payment-methods/{$id}", ['name_en' => 'Mine'], $creator)->assertForbidden();
        $this->postJson("/api/v1/payment-methods/{$id}/restore", [], $creator)->assertForbidden();
        $this->putJson("/api/v1/companies/{$this->acme->id}/payment-methods/order", ['ids' => []], $creator)->assertForbidden();

        // Another tenant sees nothing.
        $other = $this->otherTenant();
        $this->getJson("/api/v1/payment-methods/{$id}", $this->bearer($this->tokenFor($other['user'])))->assertNotFound();
        $this->getJson("/api/v1/companies/{$this->acme->id}/payment-methods", $this->bearer($this->tokenFor($other['user'])))->assertNotFound();
    }

    public function test_provider_settings_secrets_and_switching_on_need_configure(): void
    {
        // MD-04: provider credentials are a fraud path; only a role with
        // `core.payment_method.configure` (Owner, Admin) changes them or
        // switches on a mobile money or card method.
        $editor = $this->inTenant(function () {
            $role = $this->role('Method editor', ['core.company.view', 'core.payment_method.view', 'core.payment_method.create', 'core.payment_method.edit']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::company($this->acme->id));

            return $user;
        });
        $accountant = $this->headersFor($this->userWith('accountant', Scope::company($this->acme->id)));
        $admin = $this->headersFor($this->userWith('admin', Scope::company($this->acme->id)));
        $editor = $this->headersFor($editor);
        $mpesa = $this->idOf('mpesa_ke');
        $url = "/api/v1/payment-methods/{$mpesa}";

        // The accountant reads, nothing more.
        $this->patchJson($url, ['secrets' => ['passkey' => 'pk-'.self::SECRET]], $accountant)->assertForbidden();
        $this->patchJson($url, ['settings' => ['shortcode' => '600000']], $accountant)->assertForbidden();
        $this->patchJson($url, ['active' => true], $accountant)->assertForbidden();

        // `edit` alone renames, but neither configures nor switches M-Pesa on.
        $this->patchJson($url, ['name_en' => 'Lipa na M-Pesa'], $editor)->assertOk();
        $this->patchJson($url, ['secrets' => ['passkey' => 'pk-'.self::SECRET]], $editor)->assertForbidden();
        $this->patchJson($url, ['settings' => ['shortcode' => '600000']], $editor)->assertForbidden();
        $this->patchJson($url, ['active' => true], $editor)->assertForbidden();
        $this->postJson("/api/v1/companies/{$this->acme->id}/payment-methods", [
            'type' => 'card', 'provider' => 'card_aggregator', 'name_en' => 'Visa', 'name_fr' => 'Visa',
        ], $editor)->assertForbidden();
        // Cash and other methods without a provider stay with `edit`.
        $this->patchJson("/api/v1/payment-methods/{$this->idOf('cash:KES')}", ['active' => false], $editor)->assertOk();
        $this->patchJson("/api/v1/payment-methods/{$this->idOf('cash:KES')}", ['active' => true], $editor)->assertOk();

        $this->inTenant(function () use ($mpesa) {
            $method = PaymentMethod::findOrFail($mpesa);
            $this->assertSame([], $method->settings);
            $this->assertNull($method->secrets);
            $this->assertFalse($method->active);
        });

        // The admin configures and switches it on; the editor may then switch it off.
        $this->patchJson($url, [...$this->mpesaConfig(), 'active' => true], $admin)->assertOk()->assertJsonPath('data.active', true);
        $this->patchJson($url, ['active' => false], $editor)->assertOk()->assertJsonPath('data.active', false);
        $this->patchJson($url, ['active' => true], $editor)->assertForbidden();
    }

    public function test_history_hides_the_secret_keys_changed_when_secrets_are_hidden(): void
    {
        // RBAC-05, MD-07: the change marker names secret keys; a user from
        // whom `secrets` is hidden sees neither the keys nor the marker.
        $viewer = $this->inTenant(function () {
            $role = $this->role('Method viewer', ['core.company.view', 'core.payment_method.view']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'payment_method', 'field' => 'secrets', 'mode' => FieldRule::HIDDEN]);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::company($this->acme->id));

            return $user;
        });
        $mpesa = $this->idOf('mpesa_ke');
        $this->patchJson("/api/v1/payment-methods/{$mpesa}", [...$this->mpesaConfig(), 'name_en' => 'Lipa na M-Pesa'], $this->headersFor())->assertOk();

        $owner = $this->getJson("/api/v1/history/payment_method/{$mpesa}", $this->headersFor())->assertOk();
        $this->assertContains('core.payment_method.secrets_change', array_column($owner->json('data'), 'action'));

        $hidden = $this->getJson("/api/v1/history/payment_method/{$mpesa}", $this->headersFor($viewer))->assertOk();
        $this->assertNotContains('core.payment_method.secrets_change', array_column($hidden->json('data'), 'action'));
        $this->assertContains('core.payment_method.update', array_column($hidden->json('data'), 'action'));
        $this->assertStringNotContainsString('secrets_changed', (string) $hidden->getContent());
        $this->assertStringNotContainsString('consumer_key', (string) $hidden->getContent());
    }

    public function test_archiving_switches_a_method_off(): void
    {
        // TEN-06, MD-04: an archived method takes no money; restored, it stays off until switched on.
        $cash = $this->idOf('cash:KES');

        $this->postJson("/api/v1/payment-methods/{$cash}/archive", [], $this->headersFor())->assertOk()
            ->assertJsonPath('data.active', false)->assertJsonPath('data.archived_at', fn ($value) => $value !== null);
        $this->postJson("/api/v1/payment-methods/{$cash}/restore", [], $this->headersFor())->assertOk()
            ->assertJsonPath('data.active', false)->assertJsonPath('data.archived_at', null);
        $this->patchJson("/api/v1/payment-methods/{$cash}", ['active' => true], $this->headersFor())->assertOk()->assertJsonPath('data.active', true);
    }
}
