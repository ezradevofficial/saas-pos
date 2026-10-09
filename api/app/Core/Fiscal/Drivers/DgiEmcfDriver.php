<?php

namespace App\Core\Fiscal\Drivers;

use App\Core\Fiscal\Contracts\FiscalDriver;
use App\Core\Fiscal\FiscalResult;
use App\Core\Fiscal\LocalRejection;
use App\Core\Fiscal\Models\FiscalSettings;
use App\Core\Fiscal\Models\FiscalSubmission;

/**
 * DRC DGI normalised invoicing through an approved electronic invoicing
 * module (e-MCF). Not implemented: it reports itself unavailable, so a
 * Congolese company cannot switch transmission on with it, and nothing is
 * ever sent.
 *
 * TODO (owner input needed, docs/integrations.md): the e-MCF API the DGI
 * approves (base URL, authentication, request and response formats), and
 * for each company its NIF (`tin`), the e-MCF unit's serial and ISF
 * number (`device_serial`), its access credentials, the DGI tax groups
 * for each tax code (`fiscal_code`, codes A to P in the DGI layout) and
 * the transmission deadline for documents made offline. The driver will
 * then map FiscalDocument like EtimsPayload does (invoice: type FV,
 * credit note: FA referencing the original's DGI code) and store the
 * DGI code, counters, signature and QR content in `authority`.
 */
class DgiEmcfDriver implements FiscalDriver
{
    public function name(): string
    {
        return 'dgi_emcf';
    }

    public function available(): bool
    {
        return false;
    }

    public function required(): array
    {
        return ['tin', 'device_serial', 'credentials.api_token'];
    }

    public function initialize(FiscalSettings $settings): array
    {
        throw new LocalRejection('driver_unavailable', __('fiscal.errors.driver_unavailable'));
    }

    public function submitInvoice(FiscalSubmission $submission, FiscalSettings $settings): FiscalResult
    {
        return FiscalResult::rejected('driver_unavailable', __('fiscal.errors.driver_unavailable'));
    }

    public function submitCreditNote(FiscalSubmission $submission, FiscalSettings $settings): FiscalResult
    {
        return FiscalResult::rejected('driver_unavailable', __('fiscal.errors.driver_unavailable'));
    }

    public function status(FiscalSubmission $submission, FiscalSettings $settings): ?FiscalResult
    {
        return null;
    }
}
