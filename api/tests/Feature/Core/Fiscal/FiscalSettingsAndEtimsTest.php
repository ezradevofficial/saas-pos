<?php

namespace Tests\Feature\Core\Fiscal;

use App\Core\Audit\AuditEntry;
use App\Core\Fiscal\FiscalQueue;
use App\Core\Fiscal\Models\FiscalSettings;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Rbac\Scope;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsFiscal;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Fiscal\TestFiscalSource;
use Tests\TestCase;

// Concept note 7.2: company fiscal settings (core.fiscal.view / edit;
// credentials encrypted and never returned), KRA eTIMS OSCU initialisation
// and transmission against faked HTTP, the DGI driver that cannot be
// switched on yet, and the fake driver for a Congolese company.
class FiscalSettingsAndEtimsTest extends TestCase
{
    use BuildsFiscal, RefreshTenantDatabase;

    private const CMC_KEY = 'cmc-key-secret-91b2c7';

    /** @var array{0: array, 1: int}|null KRA's answer to saveTrnsSalesOsdc */
    private ?array $etimsAnswer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFiscal();
        config(['fiscal.etims.base_url' => 'https://etims-api-sbx.kra.go.ke/etims-api', 'fiscal.etims.retryable_codes' => ['996']]);
    }

    private function fakeEtims(array $saveAnswer = ['resultCd' => '000', 'resultMsg' => 'Successful', 'resultDt' => '20261009101531', 'data' => [
        'rcptNo' => 41, 'intrlData' => 'WTESTINTERNALDATA0000000AB', 'rcptSign' => 'SIGN1234ABCD5678', 'totRcptNo' => 120,
        'vsdcRcptPbctDate' => '20261009101531', 'sdcId' => 'KRACU0100000001', 'mrcNo' => 'WIS00000001',
    ]], int $status = 200): void
    {
        // Later calls only change KRA's answer (the fake is registered once).
        $first = $this->etimsAnswer === null;
        $this->etimsAnswer = [$saveAnswer, $status];

        if (! $first) {
            return;
        }

        Http::fake([
            'etims-api-sbx.kra.go.ke/etims-api/selectInitOsdcInfo' => Http::response(['resultCd' => '000', 'resultMsg' => 'Successful', 'data' => ['info' => [
                'tin' => 'P051111111A', 'taxprNm' => 'ACME LIMITED', 'bhfId' => '00', 'bhfNm' => 'Headquarter', 'dvcId' => 'DVC0001',
                'sdcId' => 'KRACU0100000001', 'mrcNo' => 'WIS00000001', 'cmcKey' => self::CMC_KEY,
            ]]]),
            'etims-api-sbx.kra.go.ke/etims-api/saveTrnsSalesOsdc' => fn () => Http::response($this->etimsAnswer[0], $this->etimsAnswer[1]),
        ]);
    }

    private function configureEtims(): void
    {
        $this->saveFiscalSettings(['driver' => 'kra_etims_oscu', 'tin' => 'P051111111A', 'branch_code' => '00', 'device_serial' => 'DVC-TEST-1'])->assertCreated()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.missing', ['credentials.cmc_key']);
        $this->saveFiscalSettings(['enabled' => true])->assertUnprocessable()->assertJsonPath('code', 'fiscal_not_ready');
        $this->postJson("/api/v1/companies/{$this->acme->id}/fiscal-settings/initialize", [], $this->headersFor())->assertOk()
            ->assertJsonPath('data.settings.sdc_id', 'KRACU0100000001')
            ->assertJsonPath('data.credentials_set.cmc_key', true)
            ->assertJsonPath('data.missing', []);
        $this->saveFiscalSettings(['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
    }

    private function sale(string $item = 'Sugar 1kg'): string
    {
        $id = (string) Str::uuid7();
        TestFiscalSource::sale($id, $this->acme->id, [[$item, $this->vat->id, '12.5', 22500, 2500]]);

        return $id;
    }

    public function test_initialisation_keeps_the_key_encrypted_and_never_returns_it(): void
    {
        $this->fakeEtims();
        $this->configureEtims();

        $init = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/selectInitOsdcInfo'))->first()[0];
        $this->assertSame(['tin' => 'P051111111A', 'bhfId' => '00', 'dvcSrlNo' => 'DVC-TEST-1'], $init->data());

        $shown = $this->getJson("/api/v1/companies/{$this->acme->id}/fiscal-settings", $this->headersFor())->assertOk();
        $this->assertStringNotContainsString(self::CMC_KEY, $shown->getContent());

        $this->inTenant(function () {
            $this->assertStringNotContainsString(self::CMC_KEY, (string) DB::table('company_fiscal_settings')->value('credentials'));
            $this->assertStringNotContainsString(self::CMC_KEY, AuditEntry::query()->get()->toJson());
            $this->assertSame(1, AuditEntry::query()->where('action', 'core.fiscal_settings.credentials_change')->count());
        });

        // A new device serial drops the key and switches transmission off.
        $this->saveFiscalSettings(['device_serial' => 'DVC-TEST-2'])->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.missing', ['credentials.cmc_key']);
    }

    public function test_an_accepted_sale_keeps_the_receipt_signature_and_qr(): void
    {
        $this->fakeEtims();
        $this->configureEtims();
        $sale = $this->sale();

        $this->inTenant(fn () => app(FiscalQueue::class)->enqueue(TestFiscalSource::KEY, 'sale', $sale, $this->acme->id));

        $request = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/saveTrnsSalesOsdc'))->first()[0];
        $this->assertSame(self::CMC_KEY, $request->header('cmcKey')[0]);
        $this->assertSame('P051111111A', $request->header('tin')[0]);
        $this->assertSame('00', $request->header('bhfId')[0]);
        $this->assertSame('S', $request['rcptTyCd']);
        $this->assertSame(1, $request['invcNo']);

        $status = $this->inTenant(fn () => app(FiscalQueue::class)->statusFor(TestFiscalSource::KEY, 'sale', $sale));
        $this->assertSame('accepted', $status['status']);
        $this->assertSame('SIGN1234ABCD5678', $status['authority']['receipt_signature']);
        $this->assertSame('WTESTINTERNALDATA0000000AB', $status['authority']['internal_data']);
        $this->assertSame('KRACU0100000001', $status['authority']['control_unit_id']);
        $this->assertSame('41', $status['authority']['receipt_number']);
        $this->assertStringEndsWith('P051111111A00SIGN1234ABCD5678', $status['authority']['qr']);

        $list = $this->getJson("/api/v1/companies/{$this->acme->id}/fiscal-submissions?status=accepted", $this->headersFor())->assertOk();
        $this->assertSame([$sale], array_column($list->json('data'), 'document_id'));
        $this->assertStringNotContainsString(self::CMC_KEY, $list->getContent());
        $detail = $this->getJson('/api/v1/fiscal-submissions/'.$list->json('data.0.id'), $this->headersFor())->assertOk();
        $detail->assertJsonPath('data.payload.lines.0.item_name', 'Sugar 1kg');
    }

    public function test_kra_refusals_are_rejected_and_listed_codes_and_outages_are_retried(): void
    {
        $this->fakeEtims(['resultCd' => '910', 'resultMsg' => 'Request parameter error: itemClsCd']);
        $this->configureEtims();
        $refused = $this->sale();
        $this->inTenant(fn () => app(FiscalQueue::class)->enqueue(TestFiscalSource::KEY, 'sale', $refused, $this->acme->id));
        $row = $this->inTenant(fn () => app(FiscalQueue::class)->find(TestFiscalSource::KEY, 'sale', $refused));
        $this->assertSame('rejected', $row->status);
        $this->assertSame('etims_910', $row->error_code);
        $this->assertStringContainsString('itemClsCd', $row->last_error);

        $this->fakeEtims(['resultCd' => '996', 'resultMsg' => 'Busy'], 200);
        $busy = $this->sale();
        $this->inTenant(fn () => app(FiscalQueue::class)->enqueue(TestFiscalSource::KEY, 'sale', $busy, $this->acme->id));
        $this->assertSame('retrying', $this->inTenant(fn () => app(FiscalQueue::class)->find(TestFiscalSource::KEY, 'sale', $busy))->status);

        $this->fakeEtims(['message' => 'Bad gateway'], 502);
        $down = $this->sale();
        $this->inTenant(fn () => app(FiscalQueue::class)->enqueue(TestFiscalSource::KEY, 'sale', $down, $this->acme->id));
        $this->assertSame('authority_unavailable', $this->inTenant(fn () => app(FiscalQueue::class)->find(TestFiscalSource::KEY, 'sale', $down))->error_code);
    }

    public function test_settings_need_core_fiscal_permissions_and_stay_in_the_tenant(): void
    {
        $this->enableFakeFiscal();

        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->getJson("/api/v1/companies/{$this->acme->id}/fiscal-settings", $this->headersFor($manager))->assertOk();
        $this->putJson("/api/v1/companies/{$this->acme->id}/fiscal-settings", ['enabled' => false], $this->headersFor($manager))->assertForbidden();
        $this->getJson("/api/v1/companies/{$this->acme->id}/fiscal-submissions", $this->headersFor($manager))->assertOk();

        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->getJson("/api/v1/companies/{$this->acme->id}/fiscal-settings", $this->headersFor($cashier))->assertNotFound();

        $other = $this->otherTenant();
        $this->getJson("/api/v1/companies/{$this->acme->id}/fiscal-settings", $this->headersFor($other['user']))->assertNotFound();

        // Unknown keys and drivers of another country are refused.
        $this->saveFiscalSettings(['credentials' => ['password' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('credentials');
        $this->saveFiscalSettings(['driver' => 'dgi_emcf'])->assertUnprocessable()->assertJsonValidationErrors('driver');
    }

    public function test_the_fake_driver_is_refused_outside_development_and_dgi_cannot_be_switched_on_yet(): void
    {
        config(['fiscal.allow_fake' => false]);
        $this->saveFiscalSettings(['driver' => 'fake', 'tin' => 'P051111111A'])->assertUnprocessable()->assertJsonValidationErrors('driver');
        config(['fiscal.allow_fake' => true]);

        $congo = $this->inTenant(function () {
            $company = $this->company('Kinshasa SARL');
            $company->forceFill(['country' => 'CD', 'base_currency' => 'USD', 'timezone' => 'Africa/Kinshasa'])->save();

            return $company;
        });

        $this->saveFiscalSettings(['driver' => 'dgi_emcf', 'tin' => 'A1234567B', 'device_serial' => 'EMCF-1', 'credentials' => ['api_token' => 'tok']], $congo->id)
            ->assertCreated()->assertJsonPath('data.drivers', ['dgi_emcf', 'fake']);
        $this->saveFiscalSettings(['enabled' => true], $congo->id)->assertUnprocessable()->assertJsonPath('code', 'driver_unavailable');
        $this->postJson("/api/v1/companies/{$congo->id}/fiscal-settings/initialize", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'driver_unavailable');

        // The DGI path runs end to end through the fake driver.
        $this->saveFiscalSettings(['driver' => 'fake', 'enabled' => true], $congo->id)->assertOk()->assertJsonPath('data.driver', 'fake');
        $sale = (string) Str::uuid7();
        $this->inTenant(function () use ($congo) {
            TaxCode::create(['company_id' => $congo->id, 'code' => 'TVA_T', 'name' => 'TVA (test)', 'kind' => 'vat', 'fiscal_code' => 'B']);
        });
        $code = $this->inTenant(fn () => TaxCode::query()->where('company_id', $congo->id)->sole()->id);
        TestFiscalSource::sale($sale, $congo->id, [['Pain', $code, '16', 116000, 16000]], ['currency' => 'CDF']);
        $submission = $this->inTenant(fn () => app(FiscalQueue::class)->enqueue(TestFiscalSource::KEY, 'sale', $sale, $congo->id));
        $this->assertSame('CD', $submission->country);
        $this->assertSame('accepted', $submission->refresh()->status);
        $this->assertSame(1, $this->inTenant(fn () => FiscalSettings::query()->where('company_id', $congo->id)->value('next_invoice_no')) - 1);
    }
}
