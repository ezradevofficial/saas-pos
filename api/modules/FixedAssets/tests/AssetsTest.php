<?php

namespace Modules\FixedAssets\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Modules\FixedAssets\Events\AssetCapitalised;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Posting;
use Modules\FixedAssets\Tests\Concerns\BuildsFixedAssets;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// FA-04 to FA-06, FA-11, FA-18, FA-27, FA-29, FA-30: the register through
// the API: drafts, activation (capitalisation), cancellation, changes of
// estimate, readings and documents; scope, permission-denied and another
// tenant's ids.
class AssetsTest extends TestCase
{
    use BuildsFixedAssets, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixedAssets();
    }

    public function test_a_draft_is_tagged_with_the_category_terms_and_edited_freely(): void
    {
        $draft = $this->draft(['serial_number' => 'KDA 123X', 'cost_centre_id' => $this->operations->id, 'custodian_user_id' => $this->manager->id]);

        $this->assertSame('draft', $draft['status']);
        $this->assertMatchesRegularExpression('/^FA-.+-00001$/', $draft['number']);
        $this->assertSame(['straight_line', 60], [$draft['method'], $draft['useful_life_months']]);
        $this->assertSame(['type' => 'user', 'id' => $this->manager->id, 'name' => $this->manager->name], $draft['custodian']);
        $this->assertSame(['amount_minor' => '120000000', 'currency' => 'KES'], $draft['cost']);
        $this->assertTrue($draft['can']['activate']);

        $edited = $this->api('PATCH', "assets/{$draft['id']}", ['name' => 'Van', 'category_id' => $this->computers->id, 'location_id' => $this->locationB->id])->assertOk()->json('data');
        $this->assertSame(['Van', 'declining_balance', '40.0000', $this->locationB->id], [$edited['name'], $edited['method'], $edited['declining_rate'], $edited['location']['id']]);
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::query()->where('action', 'fixed_assets.asset.create')->count()));
    }

    public function test_activation_capitalises_at_the_acquisition_rate_and_raises_the_posting(): void
    {
        Event::fake([AssetCapitalised::class]);
        $draft = $this->draft(['currency' => 'USD', 'cost_minor' => 1000000, 'acquired_on' => '2026-02-10']);

        $asset = $this->api('POST', "assets/{$draft['id']}/activate", ['in_service_on' => '2026-02-15'])->assertOk()->json('data');

        // USD 10 000.00 at 130 = KES 1 300 000.00; residual 10 % of the base cost.
        $this->assertSame('active', $asset['status']);
        $this->assertSame(['amount_minor' => '130000000', 'currency' => 'KES'], $asset['base_cost']);
        $this->assertSame(['amount_minor' => '13000000', 'currency' => 'KES'], $asset['residual']);
        $this->assertSame(['amount_minor' => '130000000', 'currency' => 'KES'], $asset['book_value']);
        $this->assertSame('2026-02-15', $asset['in_service_on']);
        Event::assertDispatched(AssetCapitalised::class, fn (AssetCapitalised $e) => $e->amounts === ["cost.{$this->vehicles->id}" => '130000000', 'manual' => '130000000']
            && $e->date === '2026-02-10' && $e->currency === 'KES' && $e->dimensions['branch_id'] === $this->branchA->id);

        // Only drafts activate.
        $this->api('POST', "assets/{$draft['id']}/activate")->assertStatus(409)->assertJsonPath('code', 'asset_not_draft');
        // An active asset changes only its description; its place moves by transfer.
        $this->api('PATCH', "assets/{$draft['id']}", ['name' => 'Truck', 'location_id' => $this->locationB->id, 'cost_minor' => 5])->assertOk()
            ->assertJsonPath('data.name', 'Truck')->assertJsonPath('data.location.id', $this->locationA->id)->assertJsonPath('data.cost.amount_minor', '1000000');
    }

    public function test_activation_needs_a_category_a_cost_and_the_method_terms(): void
    {
        $draft = $this->draft(['category_id' => null, 'cost_minor' => 0]);
        $this->api('POST', "assets/{$draft['id']}/activate")->assertStatus(422)->assertJsonPath('code', 'category_required');
        $this->api('PATCH', "assets/{$draft['id']}", ['category_id' => $this->plant->id])->assertOk();
        $this->api('POST', "assets/{$draft['id']}/activate")->assertStatus(422)->assertJsonPath('code', 'cost_required');
        $this->api('PATCH', "assets/{$draft['id']}", ['cost_minor' => 500000])->assertOk();
        // Units of production need the total units.
        $this->api('POST', "assets/{$draft['id']}/activate")->assertStatus(422)->assertJsonPath('code', 'method_terms')->assertJsonValidationErrors('total_units');
        $this->api('PATCH', "assets/{$draft['id']}", ['total_units' => '10000'])->assertOk();
        $this->api('POST', "assets/{$draft['id']}/activate")->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_a_typed_tag_is_unique_in_the_company_and_a_draft_is_cancelled_never_deleted(): void
    {
        $draft = $this->draft(['number' => 'VAN-1']);
        $this->api('POST', 'assets', ['location_id' => $this->locationA->id, 'name' => 'Other', 'number' => 'VAN-1'])->assertStatus(422)->assertJsonPath('code', 'tag_taken');

        $this->api('POST', "assets/{$draft['id']}/cancel", ['reason' => 'Bought by mistake'])->assertOk()->assertJsonPath('data.archived_at', fn ($v) => $v !== null);
        $this->assertSame(1, $this->inTenant(fn () => Asset::query()->whereKey($draft['id'])->count()));
        $this->api('GET', 'assets')->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', 'assets?status=archived')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_change_of_estimate_is_kept_in_the_history(): void
    {
        $asset = $this->active();
        $this->api('POST', "assets/{$asset['id']}/estimates", ['useful_life_months' => 48, 'residual_minor' => 0])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->api('POST', "assets/{$asset['id']}/estimates", ['useful_life_months' => 48, 'residual_minor' => 0, 'reason' => 'Heavier use'])->assertOk()
            ->assertJsonPath('data.useful_life_months', 48)->assertJsonPath('data.residual.amount_minor', '0');

        $history = $this->api('GET', "assets/{$asset['id']}/history")->assertOk()->json('data');
        $this->assertSame(['estimate_changed', 'activated', 'created'], array_column($history, 'kind'));
        $this->assertSame(60, $history[0]['before']['useful_life_months']);
        $this->assertSame(48, $history[0]['after']['useful_life_months']);
    }

    public function test_history_without_a_date_is_dated_on_the_companys_day_not_utcs(): void
    {
        // FA-26: 00:30 on Tuesday 20 October 2026 in Nairobi; still Monday 19 October in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-19T21:30:00Z'));
        $asset = $this->active();
        $this->api('POST', "assets/{$asset['id']}/estimates", ['useful_life_months' => 48, 'residual_minor' => 0, 'reason' => 'Heavier use'])->assertOk();

        $history = collect($this->api('GET', "assets/{$asset['id']}/history")->assertOk()->json('data'))->keyBy('kind');
        $this->assertSame(['2026-10-20', '2026-10-20'], [$history['created']['happened_on'], $history['estimate_changed']['happened_on']]);
    }

    public function test_meter_readings_never_go_down(): void
    {
        $asset = $this->active();
        $this->api('POST', "assets/{$asset['id']}/readings", ['read_on' => '2026-03-01', 'reading' => '1200'])->assertCreated();
        $this->api('POST', "assets/{$asset['id']}/readings", ['read_on' => '2026-04-01', 'reading' => '1100'])->assertStatus(422)->assertJsonPath('code', 'reading_decreases');
        $this->api('POST', "assets/{$asset['id']}/readings", ['read_on' => '2026-02-01', 'reading' => '1300'])->assertStatus(422)->assertJsonPath('code', 'reading_decreases');
        $this->api('POST', "assets/{$asset['id']}/readings", ['read_on' => '2026-04-01', 'reading' => '1500.5'])->assertCreated();
        $this->api('GET', "assets/{$asset['id']}/readings")->assertOk()->assertJsonPath('data.0.reading', '1500.500000');
    }

    public function test_documents_are_stored_privately_downloaded_and_archived(): void
    {
        Storage::fake('media');
        $asset = $this->active();
        $document = $this->json('POST', "/api/v1/fixed-assets/assets/{$asset['id']}/documents", ['file' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf')],
            $this->headersFor())->assertCreated()->json('data');
        $this->json('POST', "/api/v1/fixed-assets/assets/{$asset['id']}/documents", ['file' => UploadedFile::fake()->create('run.exe', 2, 'application/x-msdownload')],
            $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'document_type');

        $this->get("/api/v1/fixed-assets/documents/{$document['id']}/download", $this->headersFor())->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->api('POST', "documents/{$document['id']}/archive")->assertOk();
        $this->get("/api/v1/fixed-assets/documents/{$document['id']}/download", $this->headersFor())->assertNotFound();
        $this->api('GET', "assets/{$asset['id']}/documents")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_custodians_are_users_or_hr_employees_never_both(): void
    {
        $this->api('POST', 'assets', ['location_id' => $this->locationA->id, 'name' => 'Laptop', 'custodian_user_id' => $this->manager->id, 'custodian_employee_id' => $this->manager->id])
            ->assertStatus(422)->assertJsonValidationErrors('custodian_employee_id');
        // HR is off: an employee id is not known (with HR on, naming both answers custodian_both).
        $this->api('POST', 'assets', ['location_id' => $this->locationA->id, 'name' => 'Laptop', 'custodian_employee_id' => $this->manager->id])
            ->assertStatus(422)->assertJsonValidationErrors(['custodian_employee_id' => 'Choose a custodian who exists in this company.']);
        $this->api('GET', "custodians?company={$this->acme->id}&search=")->assertOk()->assertJsonPath('hr', false);
    }

    public function test_scope_permission_denied_and_other_tenants(): void
    {
        $asset = $this->active();
        $atB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));

        // Out of scope: not found; in scope without the permission: forbidden.
        $this->api('GET', "assets/{$asset['id']}", [], $atB)->assertNotFound();
        $this->api('GET', 'assets', [], $atB)->assertOk()->assertJsonCount(0, 'data');
        $this->api('POST', 'assets', ['location_id' => $this->locationA->id, 'name' => 'Desk'], $atB)->assertStatus(422)->assertJsonValidationErrors('location_id');
        $this->api('GET', 'assets', [], $cashier)->assertForbidden();
        $auditor = $this->userWith('read_only_auditor', Scope::company($this->acme->id));
        $this->api('GET', "assets/{$asset['id']}", [], $auditor)->assertOk()->assertJsonPath('data.can.edit', false);
        $this->api('PATCH', "assets/{$asset['id']}", ['name' => 'X'], $auditor)->assertForbidden();

        // RBAC-05: no values without fixed_assets.value.view (a custom role with the view only).
        $viewer = $this->userWith('read_only_auditor', Scope::company($this->acme->id));
        $this->inTenant(fn () => $this->roles->get('read_only_auditor')->revokePermissionTo('fixed_assets.value.view'));
        $this->api('GET', "assets/{$asset['id']}", [], $viewer)->assertOk()->assertJsonPath('data.book_value', null)->assertJsonPath('data.cost', null);

        // Another tenant's ids are never found, and its records never offered.
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('fixed_assets'));
        $this->getJson("/api/v1/fixed-assets/assets/{$asset['id']}", $this->headersFor($other['user']))->assertNotFound();
        $this->getJson('/api/v1/fixed-assets/assets', $this->headersFor($other['user']))->assertOk()->assertJsonCount(0, 'data');
        $this->api('POST', 'assets', ['location_id' => $other['location']->id, 'name' => 'Desk'])->assertStatus(422)->assertJsonValidationErrors('location_id');
        $this->api('POST', 'assets', ['location_id' => $this->locationA->id, 'name' => 'Desk', 'category_id' => $other['company']->id])->assertStatus(422)->assertJsonValidationErrors('category_id');
    }

    public function test_capitalisation_postings_are_kept_for_the_backfill(): void
    {
        $asset = $this->active();

        $this->assertSame(1, $this->inTenant(fn () => Posting::query()->where('document_id', $asset['id'])->where('source_type', 'fixed_assets.capitalisation')->count()));
    }
}
