<?php

namespace Tests\Feature\Core\Currency;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\Models\ExchangeRate;
use App\Core\Currency\Models\RateAlert;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\TenantCurrencies;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CUR-03, CUR-07: rate history, shop rates with tolerance alerts, current rates.
class ExchangeRateApiTest extends TestCase
{
    use BuildsExchangeRates, BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->congoCurrencies();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/companies/{$this->acme->id}/exchange-rates{$suffix}";
    }

    public function test_the_owner_enters_a_shop_rate_and_it_is_audited(): void
    {
        $response = $this->postJson($this->url(), [
            'base' => 'USD', 'quote' => 'CDF', 'mid' => '2850', 'buy' => '2820.5', 'sell' => '2880',
            'effective_at' => '2026-10-08T07:00:00Z',
        ], $this->headersFor())->assertCreated();

        $response->assertJsonPath('data.pair', 'USD/CDF')
            ->assertJsonPath('data.kind', 'shop')
            ->assertJsonPath('data.mid', '2850.00000000')
            ->assertJsonPath('data.buy', '2820.50000000')
            ->assertJsonPath('data.sell', '2880.00000000')
            ->assertJsonPath('data.effective_at', '2026-10-08T07:00:00+00:00')
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.entered_by', $this->owner->id)
            ->assertJsonMissingPath('meta.warning');

        $this->inTenant(function () use ($response) {
            $this->assertSame('2850.00000000', ExchangeRate::findOrFail($response->json('data.id'))->mid);
            $this->assertSame(1, AuditEntry::where('action', 'core.exchange_rate.create')->count());
        });
    }

    public function test_a_shop_rate_beyond_the_tolerance_is_saved_with_an_alert_an_audit_entry_and_a_warning(): void
    {
        $this->rate('USD', 'CDF', '2850', 'reference', '-1 day');

        $response = $this->postJson($this->url(), ['base' => 'USD', 'quote' => 'CDF', 'mid' => '3000'], $this->headersFor())
            ->assertCreated()
            ->assertJsonPath('meta.warning.code', 'rate_tolerance_exceeded')
            ->assertJsonPath('meta.warning.previous_mid', '2850.00000000')
            ->assertJsonPath('meta.warning.change_percent', '5.2632')
            ->assertJsonPath('meta.warning.tolerance_percent', '5.00');

        $this->assertStringContainsString('USD/CDF', $response->json('meta.warning.message'));

        $this->inTenant(function () use ($response) {
            $alert = RateAlert::sole();
            $this->assertSame($response->json('data.id'), $alert->exchange_rate_id);
            $this->assertSame(['USD/CDF', '2850.00000000', '3000.00000000', '5.2632', $this->owner->id], [$alert->pair, $alert->previous_mid, $alert->new_mid, $alert->change_percent, $alert->entered_by]);

            $entry = AuditEntry::where('action', 'core.exchange_rate.alert')->sole();
            $this->assertSame(['mid' => '2850.00000000'], $entry->before);
            $this->assertSame('5.2632', $entry->after['change_percent']);
            $this->assertSame($response->json('data.id'), $entry->auditable_id);
        });
    }

    public function test_within_the_tolerance_or_against_the_inverse_pair_no_alert_is_raised(): void
    {
        $this->rate('USD', 'CDF', '2850', 'shop', '-1 day');
        $this->postJson($this->url(), ['base' => 'USD', 'quote' => 'CDF', 'mid' => '2990'], $this->headersFor())
            ->assertCreated()->assertJsonMissingPath('meta.warning'); // 4.91 %

        // CDF/USD 0.00033445 = 1/2990: compared through the inverse, no move.
        $this->postJson($this->url(), ['base' => 'CDF', 'quote' => 'USD', 'mid' => '0.00033445'], $this->headersFor())
            ->assertCreated()->assertJsonMissingPath('meta.warning');

        $this->inTenant(fn () => $this->assertSame(0, RateAlert::count()));
    }

    public function test_the_tolerance_is_a_company_setting(): void
    {
        $this->patchJson("/api/v1/companies/{$this->acme->id}", ['rate_tolerance_percent' => '10', 'rate_feed' => 'bcc'], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.rate_tolerance_percent', '10.00')
            ->assertJsonPath('data.rate_feed', 'bcc');
        $this->patchJson("/api/v1/companies/{$this->acme->id}", ['rate_tolerance_percent' => '100.5', 'rate_feed' => 'ecb'], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rate_tolerance_percent', 'rate_feed']);

        $this->rate('USD', 'CDF', '2850', 'shop', '-1 day');
        $this->postJson($this->url(), ['base' => 'USD', 'quote' => 'CDF', 'mid' => '3000'], $this->headersFor())
            ->assertCreated()->assertJsonMissingPath('meta.warning');
    }

    public function test_new_companies_default_to_no_feed_and_a_5_percent_tolerance(): void
    {
        $this->postJson('/api/v1/companies', ['name' => 'Kin', 'country' => 'CD'], $this->headersFor())
            ->assertCreated()
            ->assertJsonPath('data.rate_feed', 'none')
            ->assertJsonPath('data.rate_tolerance_percent', '5.00');
    }

    public function test_validation(): void
    {
        $cases = [
            [['base' => 'USD', 'quote' => 'USD', 'mid' => '1'], ['quote']],
            [['base' => 'USD', 'quote' => 'EUR', 'mid' => '1'], ['quote']], // not active
            [['base' => 'USD', 'quote' => 'CDF', 'mid' => '0'], ['mid']],
            [['base' => 'USD', 'quote' => 'CDF', 'mid' => 2850.5], ['mid']], // a float
            [['base' => 'USD', 'quote' => 'CDF', 'mid' => '1.123456789'], ['mid']],
            [['base' => 'USD', 'quote' => 'CDF', 'mid' => '12345678901'], ['mid']],
            [['base' => 'USD', 'quote' => 'CDF', 'mid' => '2850', 'buy' => '2900', 'sell' => '2800'], ['buy', 'sell']],
            [['quote' => 'CDF', 'mid' => '2850', 'effective_at' => 'soon'], ['base', 'effective_at']],
        ];

        foreach ($cases as [$body, $errors]) {
            $this->postJson($this->url(), $body, $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors($errors);
        }

        $this->inTenant(fn () => $this->assertSame(0, ExchangeRate::count()));
    }

    public function test_a_second_shop_rate_at_the_same_time_is_refused(): void
    {
        $body = ['base' => 'USD', 'quote' => 'CDF', 'mid' => '2850', 'effective_at' => '2026-10-08T07:00:00Z'];

        $this->postJson($this->url(), $body, $this->headersFor())->assertCreated();
        $this->postJson($this->url(), $body, $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['effective_at' => __('core.exchange_rate.duplicate')]);
    }

    public function test_override_needs_the_permission_at_the_company_scope(): void
    {
        $body = ['base' => 'USD', 'quote' => 'CDF', 'mid' => '2850'];

        // A branch manager sees the rates but cannot enter one.
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->getJson($this->url(), $this->headersFor($manager))->assertOk();
        $this->postJson($this->url(), $body, $this->headersFor($manager))->assertForbidden();

        // An admin at the company scope can.
        $admin = $this->userWith('admin', Scope::company($this->acme->id));
        $this->postJson($this->url(), $body, $this->headersFor($admin))->assertCreated();

        // An admin at a branch holds the permission, but not at the company.
        $branchAdmin = $this->userWith('admin', Scope::branch($this->branchA->id));
        $this->postJson($this->url(), $body + ['effective_at' => '2026-10-01T00:00:00Z'], $this->headersFor($branchAdmin))->assertForbidden();
    }

    public function test_override_implies_view(): void
    {
        $user = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Rate setter', ['core.exchange_rate.override']), Scope::company($this->acme->id));

            return $user;
        });

        $this->postJson($this->url(), ['base' => 'USD', 'quote' => 'CDF', 'mid' => '2850'], $this->headersFor($user))->assertCreated();
        $this->getJson($this->url(), $this->headersFor($user))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->url('/current'), $this->headersFor($user))->assertOk();
    }

    public function test_two_rates_entered_within_the_same_second_do_not_collide(): void
    {
        CarbonImmutable::setTestNow('2026-10-08 10:00:00.100000');
        $this->postJson($this->url(), ['base' => 'USD', 'quote' => 'CDF', 'mid' => '2850'], $this->headersFor())->assertCreated();
        CarbonImmutable::setTestNow('2026-10-08 10:00:00.600000');
        $this->postJson($this->url(), ['base' => 'USD', 'quote' => 'CDF', 'mid' => '2851'], $this->headersFor())->assertCreated();

        $this->getJson($this->url('/current?pair=USD/CDF'), $this->headersFor())->assertJsonPath('data.mid', '2851.00000000');
    }

    public function test_a_cashier_reads_the_current_rates_of_their_company(): void
    {
        $this->rate('USD', 'CDF', '2850', 'shop', '-1 hour');
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));

        $this->getJson($this->url('/current?pair=USD/CDF'), $this->headersFor($cashier))
            ->assertOk()
            ->assertJsonPath('data.mid', '2850.00000000');
        $this->postJson($this->url(), ['base' => 'USD', 'quote' => 'CDF', 'mid' => '2900'], $this->headersFor($cashier))->assertForbidden();

        // Storekeepers hold no rate permission and cannot see the company: not found.
        $storekeeper = $this->userWith('storekeeper', Scope::location($this->locationA->id));
        $this->getJson($this->url(), $this->headersFor($storekeeper))->assertNotFound();
    }

    public function test_current_answers_one_pair_inverse_included_or_every_pair(): void
    {
        $this->rate('USD', 'CDF', '2800', 'reference', '-2 days');
        $this->rate('USD', 'CDF', '2850', 'shop', '-1 hour');
        $this->rate('USD', 'KES', '129.5', 'reference', '-1 hour');
        $this->inTenant(fn () => app(TenantCurrencies::class)->activate('KES'));
        $this->rate('USD', 'CDF', '2900', 'shop', '+1 day');

        $this->getJson($this->url('/current?pair=CDF/USD'), $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.pair', 'CDF/USD')
            ->assertJsonPath('data.mid', '0.00035088')
            ->assertJsonPath('data.inverted', true)
            ->assertJsonPath('data.kind', 'shop');

        $all = $this->getJson($this->url('/current'), $this->headersFor())->assertOk()->json('data');
        $this->assertSame([['USD/CDF', '2850.00000000'], ['USD/KES', '129.50000000']], array_map(fn ($r) => [$r['pair'], $r['mid']], $all));

        $this->getJson($this->url('/current?pair=KES/CDF'), $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'rate_unavailable');
        $this->getJson($this->url('/current?pair=USD/EUR'), $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['pair']);

        // A pair with an inactive currency is not listed.
        $this->inTenant(fn () => TenantCurrency::where('code', 'KES')->update(['active' => false]));
        $this->assertSame(['USD/CDF'], array_column($this->getJson($this->url('/current'), $this->headersFor())->json('data'), 'pair'));
    }

    public function test_history_is_paginated_newest_first_and_filtered_by_pair_dates_and_kind(): void
    {
        // Acme's time zone is Africa/Nairobi (UTC+3).
        $this->rate('USD', 'CDF', '2800', 'reference', '2026-10-01 10:00:00Z');
        $this->rate('USD', 'CDF', '2810', 'shop', '2026-10-02 10:00:00Z');
        $this->rate('USD', 'CDF', '2820', 'shop', '2026-10-02 22:00:00Z'); // 3 Oct in Nairobi
        $this->rate('USD', 'KES', '129', 'reference', '2026-10-02 10:00:00Z');

        $all = $this->getJson($this->url(), $this->headersFor())->assertOk();
        $this->assertSame(['2820.00000000', '129.00000000', '2810.00000000', '2800.00000000'], array_column($all->json('data'), 'mid'));
        $all->assertJsonPath('meta.total', 4);

        $page = $this->getJson($this->url('?per_page=2&page=2'), $this->headersFor())->assertOk();
        $this->assertSame(['2810.00000000', '2800.00000000'], array_column($page->json('data'), 'mid'));

        $filtered = $this->getJson($this->url('?pair=USD/CDF&from=2026-10-02&to=2026-10-02&kind=shop'), $this->headersFor())->assertOk();
        $this->assertSame(['2810.00000000'], array_column($filtered->json('data'), 'mid'));

        // The pair filter matches both stored directions; `direction` says which.
        $this->rate('CDF', 'USD', '0.00035', 'shop', '2026-10-03 10:00:00Z');
        $both = $this->getJson($this->url('?pair=USD/CDF'), $this->headersFor())->assertOk()->json('data');
        $this->assertSame(
            [['CDF/USD', 'inverse'], ['USD/CDF', 'direct'], ['USD/CDF', 'direct'], ['USD/CDF', 'direct']],
            array_map(fn ($r) => [$r['pair'], $r['direction']], $both),
        );
        $this->assertSame('direct', $this->getJson($this->url('?pair=CDF/USD'), $this->headersFor())->json('data.0.direction'));
        $this->assertNull($this->getJson($this->url(), $this->headersFor())->json('data.0.direction'));

        $this->getJson($this->url('?pair=usd-cdf&from=2026-10-05&to=2026-10-01&kind=bank'), $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['pair', 'to', 'kind']);
    }

    public function test_another_tenants_company_is_not_found(): void
    {
        $other = $this->otherTenant();
        $url = "/api/v1/companies/{$other['company']->id}/exchange-rates";

        $this->getJson($url, $this->headersFor())->assertNotFound();
        $this->getJson("{$url}/current", $this->headersFor())->assertNotFound();
        $this->postJson($url, ['base' => 'USD', 'quote' => 'CDF', 'mid' => '2850'], $this->headersFor())->assertNotFound();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
