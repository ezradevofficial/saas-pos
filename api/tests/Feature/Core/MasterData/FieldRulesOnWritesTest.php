<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Currency\TenantCurrencies;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\DefaultUoms;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\PaymentMethods\DefaultPaymentMethods;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-05 on writes: an input naming a field that is hidden or read-only for
// the user is refused (422 `field_readonly`, naming it) on create and update
// of parties, items, item categories and payment methods; other fields save.
class FieldRulesOnWritesTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private const PERMISSIONS = [
        'core.company.view',
        'core.party.view', 'core.party.create', 'core.party.edit',
        'core.item.view', 'core.item.create', 'core.item.edit',
        'core.item_category.view', 'core.item_category.create', 'core.item_category.edit',
        'core.payment_method.view', 'core.payment_method.create', 'core.payment_method.edit',
    ];

    private string $eachUom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->eachUom = $this->inTenant(function () {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            DB::connection(TenantContext::CONNECTION)->transaction(fn () => app(DefaultPaymentMethods::class)->seed($this->acme));
            app(DefaultUoms::class)->seed();

            return Uom::query()->where('code', 'ea')->value('id');
        });
    }

    /**
     * A user whose only role restricts $field of $resource with $mode.
     * Built before any request signs in (the permission guard follows the request's).
     */
    private function restricted(string $resource, string $field, string $mode): User
    {
        return $this->inTenant(function () use ($resource, $field, $mode) {
            $role = $this->role("Restricted {$resource} {$field} {$mode}", self::PERMISSIONS);
            FieldRule::create(['role_id' => $role->id, 'resource' => $resource, 'field' => $field, 'mode' => $mode]);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });
    }

    /** @return array<string, User> by mode */
    private function restrictedBoth(string $resource, string $field): array
    {
        return ['readonly' => $this->restricted($resource, $field, 'readonly'), 'hidden' => $this->restricted($resource, $field, 'hidden')];
    }

    private function assertRefused($response, string $key): void
    {
        $response->assertUnprocessable()
            ->assertJsonPath('code', 'field_readonly')
            ->assertJsonPath("errors.{$key}.0", __('rbac.errors.field_readonly', ['field' => $key]));
    }

    public function test_party_fields(): void
    {
        // The rule names the model's columns; the input is `credit_limit`.
        $users = $this->restrictedBoth('party', 'credit_limit_minor');
        $party = $this->postJson('/api/v1/parties', [
            'kind' => 'organisation', 'name' => 'Duka Ltd', 'roles' => ['customer'], 'credit_limit' => '5000.00', 'credit_limit_currency' => 'KES',
        ], $this->headersFor())->assertCreated()->json('data.id');

        foreach (['readonly', 'hidden'] as $mode) {
            $headers = $this->headersFor($users[$mode]);

            $this->assertRefused($this->patchJson("/api/v1/parties/{$party}", ['credit_limit' => '9000.00', 'credit_limit_currency' => 'KES'], $headers), 'credit_limit');
            $this->assertRefused($this->postJson('/api/v1/parties', [
                'kind' => 'person', 'name' => 'Asha', 'roles' => ['customer'], 'credit_limit' => '1.00', 'credit_limit_currency' => 'KES',
            ], $headers), 'credit_limit');
            $this->patchJson("/api/v1/parties/{$party}", ['name' => "Duka {$mode}"], $headers)->assertOk();
        }

        $this->inTenant(function () use ($party) {
            $stored = Party::findOrFail($party);
            $this->assertSame([500000, 'Duka hidden'], [(int) $stored->credit_limit_minor, $stored->name]);
        });
    }

    public function test_item_fields(): void
    {
        $users = $this->restrictedBoth('item', 'barcodes');
        $nameRestricted = $this->restricted('item', 'name', 'readonly');
        $item = $this->postJson('/api/v1/items', ['code' => 'SODA', 'name_en' => 'Soda', 'type' => 'stock', 'base_uom_id' => $this->eachUom], $this->headersFor())
            ->assertCreated()->json('data.id');

        foreach (['readonly', 'hidden'] as $mode) {
            $headers = $this->headersFor($users[$mode]);

            $this->assertRefused($this->patchJson("/api/v1/items/{$item}", ['barcodes' => [['barcode' => '123456']]], $headers), 'barcodes');
            $this->assertRefused($this->postJson('/api/v1/items', [
                'code' => "NEW-{$mode}", 'name_en' => 'New', 'type' => 'stock', 'base_uom_id' => $this->eachUom, 'barcodes' => [['barcode' => '999']],
            ], $headers), 'barcodes');
            $this->patchJson("/api/v1/items/{$item}", ['name_fr' => "Soda {$mode}"], $headers)->assertOk();
        }

        // A rule on the composite `name` covers both language inputs.
        $headers = $this->headersFor($nameRestricted);
        $this->assertRefused($this->patchJson("/api/v1/items/{$item}", ['name_en' => 'Pop'], $headers), 'name_en');
        $this->patchJson("/api/v1/items/{$item}", ['type' => 'service'], $headers)->assertOk()->assertJsonPath('data.name_en', 'Soda');
    }

    public function test_item_category_fields(): void
    {
        $users = $this->restrictedBoth('item_category', 'colour');
        $category = $this->postJson('/api/v1/item-categories', ['name_en' => 'Drinks'], $this->headersFor())->assertCreated()->json('data.id');

        foreach (['readonly', 'hidden'] as $mode) {
            $headers = $this->headersFor($users[$mode]);

            $this->assertRefused($this->patchJson("/api/v1/item-categories/{$category}", ['colour' => '#112233'], $headers), 'colour');
            $this->assertRefused($this->postJson('/api/v1/item-categories', ['name_en' => "New {$mode}", 'colour' => '#112233'], $headers), 'colour');
            $this->patchJson("/api/v1/item-categories/{$category}", ['name_en' => "Drinks {$mode}"], $headers)->assertOk()
                ->assertJsonPath('data.name_en', "Drinks {$mode}");
        }
    }

    public function test_payment_method_fields(): void
    {
        $users = $this->restrictedBoth('payment_method', 'currency');
        $cash = $this->inTenant(fn () => PaymentMethod::query()->where('currency', 'KES')->value('id'));

        foreach (['readonly', 'hidden'] as $mode) {
            $headers = $this->headersFor($users[$mode]);

            $this->assertRefused($this->patchJson("/api/v1/payment-methods/{$cash}", ['currency' => 'USD'], $headers), 'currency');
            $this->assertRefused($this->postJson("/api/v1/companies/{$this->acme->id}/payment-methods", [
                'type' => 'cash', 'currency' => 'USD', 'name_en' => 'Float', 'name_fr' => 'Fonds',
            ], $headers), 'currency');
            $this->patchJson("/api/v1/payment-methods/{$cash}", ['name_en' => "Cash {$mode}"], $headers)->assertOk();
        }

        $this->inTenant(fn () => $this->assertSame(['KES', 'Cash hidden'], [PaymentMethod::findOrFail($cash)->currency, PaymentMethod::findOrFail($cash)->name_en]));
    }

    public function test_the_refusal_is_in_the_users_language(): void
    {
        $headers = $this->headersFor($this->restricted('item_category', 'colour', 'readonly'));
        $category = $this->postJson('/api/v1/item-categories', ['name_en' => 'Drinks'], $this->headersFor())->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/item-categories/{$category}", ['colour' => '#112233'], [...$headers, 'Accept-Language' => 'fr'])
            ->assertUnprocessable()->assertJsonPath('message', __('rbac.errors.field_readonly', ['field' => 'colour'], 'fr'));
    }
}
