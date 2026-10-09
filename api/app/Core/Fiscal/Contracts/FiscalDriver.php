<?php

namespace App\Core\Fiscal\Contracts;

use App\Core\Fiscal\FiscalResult;
use App\Core\Fiscal\Models\FiscalSettings;
use App\Core\Fiscal\Models\FiscalSubmission;

/**
 * A tax authority adapter (concept note 7.2): KRA eTIMS (OSCU) in Kenya,
 * DGI normalised invoicing (e-MCF) in the DRC, and a fake for tests.
 *
 * submitInvoice and submitCreditNote return a FiscalResult: accepted (with
 * the authority's references), rejected (refused: a person must act) or
 * retry (the authority could not answer: tried again later). A document
 * the driver cannot even build (a line without a fiscal code, a missing
 * classification) throws LocalRejection naming what is missing.
 */
interface FiscalDriver
{
    public function name(): string;

    /** Whether the driver can actually transmit (a real adapter, configured). */
    public function available(): bool;

    /**
     * What must be set before transmission can be switched on: settings
     * columns (`tin`, `branch_code`, `device_serial`) and credential keys
     * (`credentials.cmc_key`).
     *
     * @return list<string>
     */
    public function required(): array;

    /**
     * Register the device with the authority (eTIMS: selectInitOsdcInfo).
     * Returns what to keep: plain settings and credentials.
     *
     * @return array{settings: array<string, string>, credentials: array<string, string>}
     */
    public function initialize(FiscalSettings $settings): array;

    public function submitInvoice(FiscalSubmission $submission, FiscalSettings $settings): FiscalResult;

    public function submitCreditNote(FiscalSubmission $submission, FiscalSettings $settings): FiscalResult;

    /** Where an earlier submission stands with the authority, when it can be asked; null otherwise. */
    public function status(FiscalSubmission $submission, FiscalSettings $settings): ?FiscalResult;
}
