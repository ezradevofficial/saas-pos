<?php

namespace Tests\Feature\Core\Fiscal;

use App\Core\Audit\AuditEntry;
use App\Core\Fiscal\FiscalAlert;
use App\Core\Fiscal\FiscalQueue;
use App\Core\Fiscal\Models\FiscalSettings;
use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRates;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsFiscal;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Fiscal\TestFiscalSource;
use Tests\TestCase;

// Owner decisions on the fiscal queue (phase 4 Task 3 review):
// non-KES Kenyan documents are held as needs_attention (never guessed),
// empty eTIMS bands are sent at 0, earlier sales are sent only on request
// ("Send earlier sales"), and the authority's credentials, the driver and
// the on/off switch need core.fiscal.configure (Owner, Admin).
class FiscalDecisionsTest extends TestCase
{
    use BuildsFiscal, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFiscal();
        config(['fiscal.etims.base_url' => 'https://etims-api-sbx.kra.go.ke/etims-api']);
    }

    private function etimsCompany(): void
    {
        Http::fake(['etims-api-sbx.kra.go.ke/*' => Http::response(['resultCd' => '000', 'resultMsg' => 'OK', 'data' => ['rcptSign' => 'SIG']])]);
        $this->inTenant(fn () => FiscalSettings::create([
            'company_id' => $this->acme->id, 'country' => 'KE', 'driver' => 'kra_etims_oscu', 'enabled' => true,
            'tin' => 'P051111111A', 'branch_code' => '00', 'device_serial' => 'DVC-1', 'credentials' => ['cmc_key' => 'k'],
            'settings' => ['default_item_class_code' => '5020230100'],
        ]));
    }

    private function alerts(string $event): int
    {
        return $this->inTenant(fn () => InAppNotification::query()->where('event_type', $event)->where('user_id', $this->owner->id)->count());
    }

    public function test_a_usd_sale_in_kenya_is_held_for_a_decision_and_alerted_once(): void
    {
        $this->etimsCompany();
        $sale = (string) Str::uuid7();
        TestFiscalSource::sale($sale, $this->acme->id, [['Sugar 1kg', $this->vat->id, '12.5', 2250, 250]], ['currency' => 'USD']);

        $this->inTenant(fn () => app(FiscalQueue::class)->enqueue(TestFiscalSource::KEY, 'sale', $sale, $this->acme->id));
        Artisan::call('fiscal:process', ['--at' => CarbonImmutable::now()->addDay()->toIso8601String()]);

        $held = $this->inTenant(fn () => FiscalSubmission::query()->sole());
        $this->assertSame('needs_attention', $held->status);
        $this->assertSame('currency_unconfirmed', $held->error_code);
        $this->assertStringContainsString('eTIMS currency handling for USD sales needs confirming', $held->last_error);
        $this->assertNull($held->next_attempt_at);
        $this->assertSame(1, $this->alerts(FiscalAlert::NEEDS_ATTENTION));
        $this->assertSame(0, Http::recorded(fn (Request $r) => str_contains($r->url(), 'saveTrnsSalesOsdc'))->count());
        $this->assertSame('pending', $this->inTenant(fn () => app(FiscalQueue::class)->statusFor(TestFiscalSource::KEY, 'sale', $sale))['status']);
        $this->getJson("/api/v1/companies/{$this->acme->id}/fiscal-submissions?status=needs_attention", $this->headersFor())->assertOk()->assertJsonPath('data.0.id', $held->id);
    }

    public function test_bands_without_lines_are_sent_at_zero(): void
    {
        $this->etimsCompany();
        $this->inTenant(function () {
            $eight = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'VAT_E', 'name' => 'VAT E (test figure)', 'kind' => 'vat', 'fiscal_code' => 'E']);
            app(TaxRates::class)->add($eight, '8', CarbonImmutable::parse('2026-01-01'));
        });
        $sale = (string) Str::uuid7();
        TestFiscalSource::sale($sale, $this->acme->id, [['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500]]);

        $this->inTenant(fn () => app(FiscalQueue::class)->enqueue(TestFiscalSource::KEY, 'sale', $sale, $this->acme->id));

        $body = Http::recorded(fn (Request $r) => str_contains($r->url(), 'saveTrnsSalesOsdc'))->first()[0]->data();
        $this->assertEquals(12.5, $body['taxRtB']);
        $this->assertEquals(0, $body['taxRtE']);
        $this->assertEquals(0, $body['taxRtA']);
    }

    public function test_earlier_sales_are_sent_only_on_request_from_a_date(): void
    {
        $before = (string) Str::uuid7();
        $older = (string) Str::uuid7();
        TestFiscalSource::sale($before, $this->acme->id, [['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500]], ['issued_at' => '2026-10-05T08:00:00Z']);
        TestFiscalSource::sale($older, $this->acme->id, [['Rice', $this->vat->id, '12.5', 22500, 2500]], ['issued_at' => '2026-09-01T08:00:00Z']);
        $send = fn (array $body) => $this->postJson("/api/v1/companies/{$this->acme->id}/fiscal-submissions/send-earlier", $body, $this->headersFor());

        $send(['from' => '2026-10-01', 'confirm' => true])->assertUnprocessable()->assertJsonPath('code', 'fiscal_not_enabled');

        // Switching transmission on does not send them by itself.
        $this->enableFakeFiscal();
        $this->assertSame(0, $this->inTenant(fn () => FiscalSubmission::query()->count()));

        $send(['from' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $send(['from' => now()->addDay()->toDateString(), 'confirm' => true])->assertUnprocessable()->assertJsonValidationErrors('from');

        $accountant = $this->userWith('accountant', Scope::company($this->acme->id));
        $this->postJson("/api/v1/companies/{$this->acme->id}/fiscal-submissions/send-earlier", ['from' => '2026-10-01', 'confirm' => true], $this->headersFor($accountant))
            ->assertStatus(202)->assertJsonPath('data.queued', true);
        $send(['from' => '2026-10-01', 'confirm' => true])->assertStatus(202);

        $this->inTenant(function () use ($before) {
            $this->assertSame([$before], FiscalSubmission::query()->pluck('document_id')->all());
            $this->assertSame('accepted', FiscalSubmission::query()->sole()->status);
            $this->assertSame(2, AuditEntry::query()->where('action', 'core.fiscal.send_earlier')->count());
        });

        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->postJson("/api/v1/companies/{$this->acme->id}/fiscal-submissions/send-earlier", ['from' => '2026-09-01', 'confirm' => true], $this->headersFor($manager))->assertForbidden();
    }

    public function test_credentials_driver_and_switch_need_configure(): void
    {
        $this->enableFakeFiscal();
        $accountant = $this->userWith('accountant', Scope::company($this->acme->id));
        $as = $this->headersFor($accountant);
        $url = "/api/v1/companies/{$this->acme->id}/fiscal-settings";

        // Non-secret settings and retries: the Accountant.
        $this->putJson($url, ['settings' => ['default_item_class_code' => '5020230100']], $as)->assertOk();
        // Credentials, the driver, the switch and initialisation: Owner or Admin only.
        $this->putJson($url, ['credentials' => ['cmc_key' => 'x']], $as)->assertForbidden();
        $this->putJson($url, ['enabled' => false], $as)->assertForbidden();
        $this->putJson($url, ['driver' => 'fake'], $as)->assertForbidden();
        $this->postJson("{$url}/initialize", [], $as)->assertForbidden();

        $admin = $this->userWith('admin', Scope::tenant());
        $this->putJson($url, ['enabled' => false], $this->headersFor($admin))->assertOk()->assertJsonPath('data.enabled', false);

        $sale = (string) Str::uuid7();
        TestFiscalSource::sale($sale, $this->acme->id, [['REJECT item', $this->vat->id, '12.5', 22500, 2500]]);
        $this->putJson($url, ['enabled' => true], $this->headersFor())->assertOk();
        $rejected = $this->inTenant(fn () => app(FiscalQueue::class)->enqueue(TestFiscalSource::KEY, 'sale', $sale, $this->acme->id)->refresh());
        $this->assertSame('rejected', $rejected->status);
        $this->postJson("/api/v1/fiscal-submissions/{$rejected->id}/retry", [], $as)->assertOk();
    }
}
