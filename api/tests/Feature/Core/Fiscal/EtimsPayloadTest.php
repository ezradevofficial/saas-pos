<?php

namespace Tests\Feature\Core\Fiscal;

use App\Core\Fiscal\Etims\EtimsPayload;
use App\Core\Fiscal\LocalRejection;
use App\Core\Fiscal\Models\FiscalSettings;
use App\Core\Fiscal\Models\FiscalSubmission;
use Tests\Concerns\BuildsFiscal;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Fiscal\TestFiscalSource;
use Tests\TestCase;

// Concept note 7.2: a document as KRA eTIMS OSCU's saveTrnsSalesOsdc
// request, compared with fixtures. Bands come from the tax codes' fiscal
// codes; rates are the ones the till applied (a test figure here); a
// band without lines takes its tax code's rate or 0; nothing is guessed.
class EtimsPayloadTest extends TestCase
{
    use BuildsFiscal, RefreshTenantDatabase;

    private const SALE = '019a0000-0000-7000-8000-00000000a001';

    private const REFUND = '019a0000-0000-7000-8000-00000000b001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFiscal();
    }

    private function settings(array $settings = []): FiscalSettings
    {
        return new FiscalSettings([
            'company_id' => $this->acme->id, 'country' => 'KE', 'driver' => 'kra_etims_oscu',
            'tin' => 'P051111111A', 'branch_code' => '00', 'device_serial' => 'DVC-TEST-1', 'settings' => $settings,
        ]);
    }

    private function submission(array $document, string $type = 'sale', int $invoiceNo = 7): FiscalSubmission
    {
        return new FiscalSubmission(['company_id' => $this->acme->id, 'document_type' => $type, 'payload' => $document, 'invoice_no' => $invoiceNo]);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/etims/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_a_sale_maps_to_the_fixture(): void
    {
        $document = TestFiscalSource::sale(self::SALE, $this->acme->id, [
            ['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500],
            ['Maize flour 2kg', $this->exempt->id, null, 30000, 0],
        ]);

        $payload = $this->inTenant(fn () => EtimsPayload::build($this->submission($document), $this->settings(), null));

        $this->assertEquals($this->fixture('sale'), json_decode(json_encode($payload), true));
    }

    public function test_a_refund_is_a_credit_note_naming_the_original_invoice(): void
    {
        $document = TestFiscalSource::creditNote('refund', self::REFUND, self::SALE, $this->acme->id, [
            ['Sugar 1kg', $this->vat->id, '12.5', 11250, 1250],
        ]);

        $payload = $this->inTenant(fn () => EtimsPayload::build($this->submission($document, 'refund', 8), $this->settings(), 7));

        $this->assertEquals($this->fixture('refund'), json_decode(json_encode($payload), true));
    }

    public function test_missing_data_stops_the_document_with_a_reason(): void
    {
        $cases = [
            'fiscal_code_missing' => [[['Cooking oil', $this->unbanded->id, '12.5', 33750, 3750]], []],
            'tax_code_missing' => [[['Gift wrap', null, null, 5000, 0]], []],
            'band_rate_conflict' => [[['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500], ['Salt', $this->vat->id, '8', 10800, 800]], []],
            'item_class_missing' => [[['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500]], ['classification_code' => null]],
            'currency_unconfirmed' => [[['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500]], ['currency' => 'USD']],
            'tax_rate_missing' => [[['Sugar 1kg', $this->vat->id, null, 22500, 2500]], []],
        ];

        foreach ($cases as $reason => [$lines, $change]) {
            $document = TestFiscalSource::sale(self::SALE, $this->acme->id, $lines);

            if (array_key_exists('classification_code', $change)) {
                $document['lines'][0]['classification_code'] = null;
            } elseif ($change !== []) {
                $document = [...$document, ...$change];
            }

            try {
                $this->inTenant(fn () => EtimsPayload::build($this->submission($document), $this->settings(), null));
                $this->fail("No rejection for {$reason}");
            } catch (LocalRejection $e) {
                $this->assertSame($reason, $e->reason);
            }
        }

        // The company's default classification fills the gap.
        $document = TestFiscalSource::sale(self::SALE, $this->acme->id, [['Sugar 1kg', $this->vat->id, '12.5', 22500, 2500]]);
        $document['lines'][0]['classification_code'] = null;
        $payload = $this->inTenant(fn () => EtimsPayload::build($this->submission($document), $this->settings(['default_item_class_code' => '9999999999']), null));
        $this->assertSame('9999999999', $payload['itemList'][0]['itemClsCd']);
    }
}
