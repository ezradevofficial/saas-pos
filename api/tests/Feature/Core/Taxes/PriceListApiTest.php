<?php

namespace Tests\Feature\Core\Taxes;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Rbac\Scope;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MD-03: price lists per company, tax-inclusive or exclusive, one active
// default per company and currency.
class PriceListApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->inTenant(fn () => app(TenantCurrencies::class)->provisionFor($this->acme));
    }

    private function create(array $body, ?array $headers = null)
    {
        return $this->postJson("/api/v1/companies/{$this->acme->id}/price-lists", $body, $headers ?? $this->headersFor());
    }

    private function defaults(): array
    {
        return $this->inTenant(fn () => PriceList::where('is_default', true)->whereNull('archived_at')->orderBy('currency')->pluck('name', 'currency')->all());
    }

    public function test_one_default_per_company_and_currency(): void
    {
        $retail = $this->create(['name' => 'Retail', 'currency' => 'KES', 'tax_inclusive' => true, 'is_default' => true])
            ->assertCreated()->assertJsonPath('data.tax_inclusive', true)->assertJsonPath('data.is_default', true)->json('data.id');
        $this->create(['name' => 'Dollar', 'currency' => 'USD', 'is_default' => true])->assertCreated()->assertJsonPath('data.tax_inclusive', false);
        $wholesale = $this->create(['name' => 'Wholesale', 'currency' => 'KES', 'is_default' => true])->assertCreated()->json('data.id');

        $this->assertSame(['KES' => 'Wholesale', 'USD' => 'Dollar'], $this->defaults());

        // Back to Retail through an update.
        $this->patchJson("/api/v1/price-lists/{$retail}", ['is_default' => true], $this->headersFor())->assertOk();
        $this->assertSame(['KES' => 'Retail', 'USD' => 'Dollar'], $this->defaults());

        // Archiving the default leaves the currency without one.
        $this->postJson("/api/v1/price-lists/{$retail}/archive", [], $this->headersFor())->assertOk()->assertJsonPath('data.is_default', false);
        $this->assertSame(['USD' => 'Dollar'], $this->defaults());
        $this->postJson("/api/v1/price-lists/{$retail}/restore", [], $this->headersFor())->assertOk()->assertJsonPath('data.archived_at', null);

        $this->getJson("/api/v1/companies/{$this->acme->id}/price-lists", $this->headersFor())->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/price-lists/{$wholesale}", $this->headersFor())->assertOk()->assertJsonPath('data.is_default', false);

        $this->inTenant(function () {
            $this->assertSame(3, AuditEntry::where('action', 'core.price_list.create')->count());
            $this->assertGreaterThanOrEqual(3, AuditEntry::where('action', 'core.price_list.update')->count());
        });
    }

    public function test_an_archived_list_cannot_be_made_the_default(): void
    {
        $id = $this->create(['name' => 'Old', 'currency' => 'KES'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/price-lists/{$id}/archive", [], $this->headersFor())->assertOk();

        $this->patchJson("/api/v1/price-lists/{$id}", ['is_default' => true], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('is_default');
        $this->inTenant(fn () => $this->assertFalse(PriceList::findOrFail($id)->is_default));

        // Other changes to an archived list are still allowed.
        $this->patchJson("/api/v1/price-lists/{$id}", ['name' => 'Older', 'is_default' => false], $this->headersFor())->assertOk();
    }

    public function test_restoring_a_former_default_keeps_the_current_default(): void
    {
        $old = $this->create(['name' => 'Old', 'currency' => 'KES', 'is_default' => true])->assertCreated()->json('data.id');

        // An archived list still flagged as default (e.g. from before archiving cleared the flag).
        $this->inTenant(function () use ($old) {
            $list = PriceList::findOrFail($old);
            $list->archive();
            $this->assertTrue($list->fresh()->is_default);
        });
        $this->create(['name' => 'New', 'currency' => 'KES', 'is_default' => true])->assertCreated();

        $this->postJson("/api/v1/price-lists/{$old}/restore", [], $this->headersFor())
            ->assertOk()->assertJsonPath('data.archived_at', null)->assertJsonPath('data.is_default', false);
        $this->assertSame(['KES' => 'New'], $this->defaults());

        // With no other default, a former default comes back as the default.
        $lone = $this->create(['name' => 'Dollar', 'currency' => 'USD', 'is_default' => true])->json('data.id');
        $this->inTenant(fn () => PriceList::findOrFail($lone)->archive());
        $this->postJson("/api/v1/price-lists/{$lone}/restore", [], $this->headersFor())->assertOk()->assertJsonPath('data.is_default', true);
    }

    public function test_the_database_refuses_a_second_active_default(): void
    {
        $this->create(['name' => 'A', 'currency' => 'KES', 'is_default' => true])->assertCreated();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->inTenant(fn () => PriceList::create(['company_id' => $this->acme->id, 'name' => 'B', 'currency' => 'KES', 'is_default' => true]));
    }

    public function test_the_currency_is_active_in_the_tenant(): void
    {
        $this->create(['name' => 'Euro', 'currency' => 'EUR'])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->create(['name' => 'Bad', 'currency' => 'kes'])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->create(['currency' => 'KES'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_scope_and_permissions(): void
    {
        $id = $this->create(['name' => 'Retail', 'currency' => 'KES'])->json('data.id');

        $manager = $this->headersFor($this->userWith('branch_manager', Scope::branch($this->branchA->id)));
        $this->getJson("/api/v1/companies/{$this->acme->id}/price-lists", $manager)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/price-lists/{$id}", $manager)->assertOk();
        $this->create(['name' => 'Mine', 'currency' => 'KES'], $manager)->assertForbidden();
        $this->patchJson("/api/v1/price-lists/{$id}", ['name' => 'Mine'], $manager)->assertForbidden();
        $this->postJson("/api/v1/price-lists/{$id}/archive", [], $manager)->assertForbidden();

        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->getJson("/api/v1/price-lists/{$id}", $cashier)->assertNotFound();

        $other = $this->otherTenant();
        $this->getJson("/api/v1/price-lists/{$id}", $this->bearer($this->tokenFor($other['user'])))->assertNotFound();
    }
}
