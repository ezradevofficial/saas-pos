<?php

namespace Modules\FixedAssets\Tests;

use Modules\FixedAssets\TaxBook\TaxClasses;
use Modules\FixedAssets\Tests\Concerns\BuildsFixedAssets;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// FA-20, CP-01, CP-02: the tax book is refused while the country pack's
// classes have no confirmed method and rate (as shipped: nothing is
// invented), and computes in integer minor units once a pack is confirmed
// (here a test pack, never real rates).
class TaxBookTest extends TestCase
{
    use BuildsFixedAssets, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixedAssets();
    }

    public function test_the_shipped_packs_hold_no_rates_and_the_tax_book_is_refused(): void
    {
        foreach (['KE', 'CD'] as $country) {
            $classes = app(TaxClasses::class)->for($country);
            $this->assertNotEmpty($classes);
            $this->assertTrue(collect($classes)->every(fn (array $c) => $c['rate'] === null && $c['method'] === null && $c['needs_confirmation']), "{$country} invents no rate");
            $this->assertNotEmpty(app(TaxClasses::class)->todo($country));
        }

        $this->api('PATCH', "categories/{$this->vehicles->id}", ['tax_class' => 'motor_vehicles'])->assertOk();
        $this->api('PATCH', "categories/{$this->vehicles->id}", ['tax_class' => 'yachts'])->assertStatus(422)->assertJsonPath('code', 'tax_class_unknown');
        $this->api('PUT', "companies/{$this->acme->id}/settings", ['tax_book' => true])->assertStatus(422)->assertJsonPath('code', 'tax_rates_unconfirmed');
        $this->api('GET', "companies/{$this->acme->id}/tax-book?year=2026")->assertOk()->assertJsonPath('status', 'unconfirmed')
            ->assertJsonPath('code', 'tax_rates_unconfirmed')->assertJsonPath('data', []);
        $settings = $this->api('GET', "companies/{$this->acme->id}/settings")->assertOk()->json('data');
        $this->assertFalse($settings['tax_book']);
        $this->assertCount(4, $settings['tax_unconfirmed']);
    }

    public function test_a_confirmed_pack_computes_the_allowances(): void
    {
        // A test pack (not a real rate): reducing balance 25 %, straight line 10 %.
        app(TaxClasses::class)->override('KE', ['classes' => [
            ['key' => 'machinery', 'method' => 'reducing_balance', 'rate' => '25', 'needs_confirmation' => false],
            ['key' => 'buildings', 'method' => 'straight_line', 'rate' => '10', 'needs_confirmation' => false],
        ]]);

        foreach ([$this->vehicles, $this->computers, $this->plant] as $category) {
            $this->api('PATCH', "categories/{$category->id}", ['tax_class' => 'machinery'])->assertOk();
        }

        $this->api('PATCH', "categories/{$this->land->id}", ['tax_class' => 'buildings'])->assertOk();
        $this->api('PUT', "companies/{$this->acme->id}/settings", ['tax_book' => true])->assertOk()->assertJsonPath('data.tax_book', true);
        $van = $this->active(['acquired_on' => '2025-03-01', 'cost_minor' => 100000001]);

        $rows = $this->api('GET', "companies/{$this->acme->id}/tax-book?year=2026")->assertOk()->assertJsonPath('status', 'confirmed')->json('data');
        $row = collect($rows)->firstWhere('asset_id', $van['id']);
        // 2025: 25 000 000.25 rounds to 25 000 000; 2026: 25 % of 75 000 001.
        $this->assertSame(['75000001', '18750000', '56250001'], [$row['opening']['amount_minor'], $row['allowance']['amount_minor'], $row['closing']['amount_minor']]);
    }
}
