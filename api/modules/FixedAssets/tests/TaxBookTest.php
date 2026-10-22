<?php

namespace Modules\FixedAssets\Tests;

use Modules\FixedAssets\Models\Category;
use Modules\FixedAssets\TaxBook\TaxClasses;
use Modules\FixedAssets\Tests\Concerns\BuildsFixedAssets;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// FA-20, CP-01, CP-02: the tax book is refused while the country pack's
// classes have no usable method and rate (CD as shipped: no statutory
// table was found), and computes in integer minor units once they do: the
// shipped KE Second Schedule classes (researched, low confidence, owner
// decision 2026-10-22) and a test pack.
class TaxBookTest extends TestCase
{
    use BuildsFixedAssets, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixedAssets();
    }

    public function test_the_cd_pack_holds_no_rates_and_the_tax_book_is_refused(): void
    {
        $classes = app(TaxClasses::class)->for('CD');
        $this->assertNotEmpty($classes);
        $this->assertTrue(collect($classes)->every(fn (array $c) => $c['rate'] === null && $c['method'] === null && $c['needs_confirmation']), 'CD invents no rate');
        $this->assertNotEmpty(app(TaxClasses::class)->todo('CD'));
        $this->inTenant(fn () => $this->acme->forceFill(['country' => 'CD'])->save());

        $this->api('PATCH', "categories/{$this->vehicles->id}", ['tax_class' => 'motor_vehicles'])->assertOk();
        $this->api('PATCH', "categories/{$this->vehicles->id}", ['tax_class' => 'yachts'])->assertStatus(422)->assertJsonPath('code', 'tax_class_unknown');
        $this->api('PUT', "companies/{$this->acme->id}/settings", ['tax_book' => true])->assertStatus(422)->assertJsonPath('code', 'tax_rates_unconfirmed');
        $this->api('GET', "companies/{$this->acme->id}/tax-book?year=2026")->assertOk()->assertJsonPath('status', 'unconfirmed')
            ->assertJsonPath('code', 'tax_rates_unconfirmed')->assertJsonPath('data', []);
        $settings = $this->api('GET', "companies/{$this->acme->id}/settings")->assertOk()->json('data');
        $this->assertFalse($settings['tax_book']);
        // The four categories without a confirmed class, then the year-of-acquisition rule.
        $this->assertCount(5, $settings['tax_unconfirmed']);
        $this->assertSame('acquisition_rule', end($settings['tax_unconfirmed'])['tax_class']);
    }

    public function test_the_shipped_kenyan_classes_compute_one_asset_per_class(): void
    {
        // Income Tax Act Second Schedule, straight line ("in equal instalments"); machinery used for manufacture
        // 50% in the first year of use, then 25% a year of the residue; the year of first use counts in full.
        $expected = [
            // class => [2026 allowance, 2027 opening, 2027 allowance, 2027 closing] on KES 1,000,000.00 (the pack's rows start 2026-01-01)
            'buildings' => [10000000, 90000000, 10000000, 80000000],
            'machinery' => [50000000, 50000000, 12500000, 37500000],
            'motor_vehicles' => [25000000, 75000000, 25000000, 50000000],
            'computers' => [25000000, 75000000, 25000000, 50000000],
            'software' => [25000000, 75000000, 25000000, 50000000],
            'furniture' => [10000000, 90000000, 10000000, 80000000],
            'telecommunication' => [10000000, 90000000, 10000000, 80000000],
            'other' => [10000000, 90000000, 10000000, 80000000],
        ];
        $categories = ['buildings' => $this->land, 'motor_vehicles' => $this->vehicles, 'computers' => $this->computers];
        // Plant (units of production in the books) takes the machinery class but holds no asset here.
        $this->api('PATCH', "categories/{$this->plant->id}", ['tax_class' => 'machinery'])->assertOk();

        foreach (array_diff_key($expected, $categories) as $key => $row) {
            $categories[$key] = $this->inTenant(fn () => Category::query()->create(['company_id' => $this->acme->id, 'code' => strtoupper(substr($key, 0, 8)), 'name' => $key, 'method' => 'none']));
        }

        $assets = [];

        foreach ($categories as $key => $category) {
            $this->api('PATCH', "categories/{$category->id}", ['tax_class' => $key])->assertOk();
            $assets[$key] = $this->active(['category_id' => $category->id, 'acquired_on' => '2026-03-01', 'cost_minor' => 100000000, 'name' => "Asset {$key}"])['id'];
        }

        // The shipped classes are researched at low confidence, with their sources: the tax book can be turned on.
        $settings = $this->api('GET', "companies/{$this->acme->id}/settings")->assertOk()->json('data');
        $this->assertSame([], $settings['tax_unconfirmed']);
        $machinery = collect($settings['tax_classes'])->firstWhere('key', 'machinery');
        $this->assertSame(['straight_line', '25', '50', 'researched', 'medium'], [$machinery['method'], $machinery['rate'], $machinery['first_year_rate'], $machinery['status'], $machinery['confidence']]);
        $this->assertSame('medium', $machinery['confidence']);
        $this->assertNotEmpty($machinery['source']);
        $this->api('PUT', "companies/{$this->acme->id}/settings", ['tax_book' => true])->assertOk();

        $first = collect($this->api('GET', "companies/{$this->acme->id}/tax-book?year=2026")->assertOk()->json('data'))->keyBy('asset_id');
        $second = collect($this->api('GET', "companies/{$this->acme->id}/tax-book?year=2027")->assertOk()->assertJsonPath('status', 'confirmed')->json('data'))->keyBy('asset_id');

        foreach ($expected as $key => [$y1, $opening, $allowance, $closing]) {
            $this->assertSame((string) $y1, $first[$assets[$key]]['allowance']['amount_minor'], "{$key} 2026");
            $row = $second[$assets[$key]];
            $this->assertSame([(string) $opening, (string) $allowance, (string) $closing], [$row['opening']['amount_minor'], $row['allowance']['amount_minor'], $row['closing']['amount_minor']], "{$key} 2027");
        }

        // Machinery's residue is written off over four more years: 2030 takes the last 12.5%, 2031 nothing.
        $later = fn (int $year) => collect($this->api('GET', "companies/{$this->acme->id}/tax-book?year={$year}")->assertOk()->json('data'))->firstWhere('asset_id', $assets['machinery']);
        $this->assertSame(['12500000', '0'], [$later(2030)['allowance']['amount_minor'], $later(2030)['closing']['amount_minor']]);
        $this->assertSame('0', $later(2031)['allowance']['amount_minor']);
    }

    public function test_a_confirmed_pack_computes_the_allowances(): void
    {
        // A test pack (not a real rate): reducing balance 25 %, straight line 10 %.
        app(TaxClasses::class)->override('KE', ['classes' => [
            ['key' => 'machinery', 'method' => 'reducing_balance', 'rate' => '25', 'needs_confirmation' => false],
            ['key' => 'buildings', 'method' => 'straight_line', 'rate' => '10', 'needs_confirmation' => false],
        ], 'acquisition_rule' => ['value' => 'full_year', 'needs_confirmation' => false]]);

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
