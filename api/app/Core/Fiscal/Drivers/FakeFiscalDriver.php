<?php

namespace App\Core\Fiscal\Drivers;

use App\Core\Fiscal\Contracts\FiscalDriver;
use App\Core\Fiscal\FiscalBands;
use App\Core\Fiscal\FiscalDocument;
use App\Core\Fiscal\FiscalResult;
use App\Core\Fiscal\Models\FiscalSettings;
use App\Core\Fiscal\Models\FiscalSubmission;

/**
 * Local and test transmission (NFR-06: `fiscal.allow_fake`). Checks the
 * fiscal bands like a real driver (FiscalBands), then answers by the
 * lines' item names: one containing `REJECT` is refused, `RETRY` gets no
 * answer (retried), anything else is accepted with deterministic
 * references derived from the company's fiscal invoice number.
 */
class FakeFiscalDriver implements FiscalDriver
{
    public function name(): string
    {
        return 'fake';
    }

    public function available(): bool
    {
        return (bool) config('fiscal.allow_fake');
    }

    public function required(): array
    {
        return ['tin'];
    }

    public function initialize(FiscalSettings $settings): array
    {
        return ['settings' => ['sdc_id' => 'FAKE-SDC-'.$settings->tin], 'credentials' => ['key' => 'fake-key-'.$settings->company_id]];
    }

    public function submitInvoice(FiscalSubmission $submission, FiscalSettings $settings): FiscalResult
    {
        return $this->answer($submission, $settings);
    }

    public function submitCreditNote(FiscalSubmission $submission, FiscalSettings $settings): FiscalResult
    {
        return $this->answer($submission, $settings);
    }

    public function status(FiscalSubmission $submission, FiscalSettings $settings): ?FiscalResult
    {
        return null;
    }

    private function answer(FiscalSubmission $submission, FiscalSettings $settings): FiscalResult
    {
        $document = FiscalDocument::fromArray($submission->payload);
        FiscalBands::resolve($document);
        $names = implode(' ', array_map(fn (array $line) => (string) $line['item_name'], $document->data['lines']));

        if (str_contains($names, 'REJECT')) {
            return FiscalResult::rejected('fake_rejected', 'The fake authority refused the document.');
        }

        if (str_contains($names, 'RETRY')) {
            return FiscalResult::retry('fake_unavailable', 'The fake authority did not answer.');
        }

        $signature = strtoupper(substr(hash('sha256', $submission->id), 0, 16));

        return FiscalResult::accepted([
            'receipt_number' => (string) $submission->invoice_no,
            'invoice_number' => 'FAKE/'.$submission->invoice_no,
            'receipt_signature' => $signature,
            'internal_data' => strtoupper(substr(hash('sha256', 'internal'.$submission->id), 0, 26)),
            'control_unit_id' => 'FAKE-SDC-'.$settings->tin,
            'qr' => 'https://fiscal.invalid/'.$settings->tin.'/'.$signature,
        ]);
    }
}
