<?php

namespace Modules\FixedAssets\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Scope;
use Illuminate\Http\UploadedFile;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Posting;
use Modules\FixedAssets\Tests\Concerns\BuildsFixedAssets;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// FA-24 to FA-26, FA-28: labels through document templates, the reports,
// the register export and saved list, the opening register import, the
// dashboard widgets and custom fields on assets.
class LabelsReportsImportTest extends TestCase
{
    use BuildsFixedAssets, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixedAssets();
    }

    public function test_labels_print_the_tag_as_a_barcode_for_assets_the_reader_sees(): void
    {
        $asset = $this->active(['serial_number' => 'SN-77']);
        $html = $this->api('POST', 'labels', ['labels' => [['asset_id' => $asset['id'], 'copies' => 2]]])->assertOk()->getContent();

        $this->assertStringContainsString($asset['number'], $html);
        $this->assertStringContainsString('SN-77', $html);
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::query()->where('action', 'fixed_assets.label.print')->count()));
        $this->api('POST', 'labels', ['labels' => [['asset_id' => $asset['id'], 'copies' => 501]]])->assertStatus(422);
        $atB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->api('POST', 'labels', ['labels' => [['asset_id' => $asset['id'], 'copies' => 1]]], $atB)->assertStatus(422)->assertJsonPath('code', 'asset_unknown');
    }

    public function test_reports_add_up_by_category_and_hide_values_from_non_holders(): void
    {
        $this->active();
        $this->active(['name' => 'Laptop', 'category_id' => $this->computers->id, 'cost_minor' => 30000000]);
        $this->api('POST', 'runs', ['company_id' => $this->acme->id, 'period' => '2026-01'])->assertCreated();

        $nbv = $this->api('GET', "reports/net-book-value?company={$this->acme->id}")->assertOk()->json('data');
        $this->assertSame(['Computers', 'Vehicles'], array_column(array_column($nbv, 'category'), 'name'));
        $depreciation = $this->api('GET', "reports/depreciation?company={$this->acme->id}&from=2026-01-01&to=2026-12-31&group=category")->assertOk()->json('data');
        $this->assertSame(array_sum(array_map(fn (array $r) => (int) $r['accumulated']['amount_minor'], $nbv)), array_sum(array_map(fn (array $r) => (int) $r['amount']['amount_minor'], $depreciation)));
        $movements = $this->api('GET', "reports/movements?company={$this->acme->id}&from=2026-01-01&to=2026-12-31")->assertOk()->json('data');
        $this->assertCount(2, $movements['additions']);

        $this->api('GET', "reports/net-book-value?company={$this->acme->id}", [], $this->manager)->assertForbidden();
        $this->inTenant(fn () => $this->roles->get('read_only_auditor')->revokePermissionTo('fixed_assets.value.view'));
        $auditor = $this->userWith('read_only_auditor', Scope::company($this->acme->id));
        $this->api('GET', "reports/net-book-value?company={$this->acme->id}", [], $auditor)->assertOk()->assertJsonPath('data.0.book_value', null);
    }

    public function test_the_register_exports_and_offers_custom_fields(): void
    {
        $this->active();
        $csv = $this->get('/api/v1/fixed-assets/assets?format=csv', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('Delivery van', $csv);
        $this->json('POST', '/api/v1/custom-fields', ['entity' => 'fixed_assets_asset', 'key' => 'insurer', 'label' => 'Insurer', 'type' => 'text'], $this->headersFor())->assertCreated();
        $asset = $this->active(['name' => 'Insured van', 'custom' => ['insurer' => 'Jubilee']]);
        $this->assertSame('Jubilee', $asset['custom']['insurer']);
    }

    public function test_the_opening_register_imports_active_assets_without_postings(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'fa-import-');
        file_put_contents($csv, "Location,Tag,Name,Category,Acquisition date,In-service date,Cost,Accumulated depreciation,Depreciated to,Residual value,Useful life (months)\n"
            ."Outlet A,OLD-1,Old truck,VEH,2024-01-01,2024-01-01,2400000.00,960000.00,2025-12-31,240000.00,60\n"
            ."Outlet A,,Bad row,NOPE,2024-01-01,,100.00,,,,\n");
        $import = $this->post('/api/v1/imports', ['importer' => 'fixed_assets', 'file' => new UploadedFile($csv, 'register.csv', 'text/csv', null, true)],
            [...$this->headersFor(), 'Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->json('PATCH', "/api/v1/imports/{$import}", ['mode' => 'create'], $this->headersFor())->assertOk();
        $this->json('POST', "/api/v1/imports/{$import}/run", [], $this->headersFor())->assertStatus(202);

        $asset = $this->inTenant(fn () => Asset::query()->where('number', 'OLD-1')->sole());
        $this->assertSame([Asset::ACTIVE, true, 240000000, 96000000, '2025-12-31'],
            [$asset->status, $asset->opening, (int) $asset->book_cost_minor, (int) $asset->accumulated_minor, $asset->depreciated_to->toDateString()]);
        $this->assertSame(0, $this->inTenant(fn () => Posting::query()->count()), 'an opening asset posts no capitalisation');
        $this->assertSame(1, $this->inTenant(fn () => Asset::query()->count()));

        // The next run charges from 2026-01-01 on what is left.
        $run = $this->api('POST', 'runs', ['company_id' => $this->acme->id, 'period' => '2026-01'])->assertCreated()->json('data');
        $this->assertSame('2026-01-01', $run['lines'][0]['from']);
    }

    public function test_dashboard_widgets_answer_for_the_reader(): void
    {
        $this->active();
        $this->json('GET', '/api/v1/dashboard/sources/fixed_assets.book_value', [], $this->headersFor())->assertOk()
            ->assertJsonPath('data.value', '120000000')->assertJsonPath('data.currency', 'KES');
        $this->json('GET', '/api/v1/dashboard/sources/fixed_assets.fully_depreciated', [], $this->headersFor())->assertOk()->assertJsonPath('data.value', 0);
        $this->json('GET', '/api/v1/dashboard/sources/fixed_assets.maintenance_due', [], $this->headersFor())->assertOk()->assertJsonPath('data.value', 0);
    }
}
