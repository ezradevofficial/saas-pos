<?php

namespace App\Core\Fiscal\Etims;

use App\Core\Fiscal\Contracts\FiscalDriver;
use App\Core\Fiscal\FiscalResult;
use App\Core\Fiscal\LocalRejection;
use App\Core\Fiscal\Models\FiscalSettings;
use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\Fiscal\NeedsAttention;

/**
 * KRA eTIMS through the online sales control unit (OSCU) API.
 *
 * - initialize: `selectInitOsdcInfo` with the PIN, branch id and device
 *   serial KRA registered; keeps the control unit id (`sdcId`), the MRC
 *   number and the device id as settings and the communication key
 *   (`cmcKey`) as a credential.
 * - submitInvoice / submitCreditNote: `saveTrnsSalesOsdc` with
 *   EtimsPayload; on `000` keeps the receipt number, total receipt
 *   number, internal data, receipt signature, control unit id, MRC number,
 *   the authority's timestamp and the QR content (the configured prefix +
 *   PIN + branch id + signature).
 * - Result codes listed in `fiscal.etims.retryable_codes`, and no usable
 *   answer at all, are retried; any other code is a rejection with KRA's
 *   message.
 * - status: OSCU offers no lookup of one invoice here; null.
 */
class EtimsOscuDriver implements FiscalDriver
{
    public function __construct(private readonly EtimsClient $client) {}

    public function name(): string
    {
        return 'kra_etims_oscu';
    }

    public function available(): bool
    {
        return filled(config('fiscal.etims.base_url'));
    }

    public function required(): array
    {
        return ['tin', 'branch_code', 'device_serial', 'credentials.cmc_key'];
    }

    public function initialize(FiscalSettings $settings): array
    {
        foreach (['tin', 'branch_code', 'device_serial'] as $field) {
            if (blank($settings->{$field})) {
                throw new LocalRejection('settings_missing', __('fiscal.errors.settings_missing', ['fields' => 'tin, branch_code, device_serial']));
            }
        }

        $answer = $this->client->post('initialize', [
            'tin' => $settings->tin,
            'bhfId' => $settings->branch_code,
            'dvcSrlNo' => $settings->device_serial,
        ], (string) $settings->tin, (string) $settings->branch_code);

        if ($answer === null) {
            throw new LocalRejection('authority_unavailable', __('fiscal.errors.authority_unavailable'));
        }

        $info = $answer['data']['info'] ?? null;

        if (($answer['resultCd'] ?? null) !== '000' || ! is_array($info) || blank($info['cmcKey'] ?? null)) {
            throw new LocalRejection('initialize_refused', __('fiscal.errors.initialize_refused', ['code' => (string) ($answer['resultCd'] ?? ''), 'message' => mb_substr((string) ($answer['resultMsg'] ?? ''), 0, 200)]));
        }

        return [
            'settings' => array_filter([
                'sdc_id' => self::text($info['sdcId'] ?? null),
                'mrc_no' => self::text($info['mrcNo'] ?? null),
                'device_id' => self::text($info['dvcId'] ?? null),
                'taxpayer_name' => self::text($info['taxprNm'] ?? null),
                'branch_name' => self::text($info['bhfNm'] ?? null),
            ]),
            'credentials' => ['cmc_key' => (string) $info['cmcKey']],
        ];
    }

    public function submitInvoice(FiscalSubmission $submission, FiscalSettings $settings): FiscalResult
    {
        return $this->send($submission, $settings, null);
    }

    public function submitCreditNote(FiscalSubmission $submission, FiscalSettings $settings): FiscalResult
    {
        return $this->send($submission, $settings, $submission->original?->invoice_no);
    }

    public function status(FiscalSubmission $submission, FiscalSettings $settings): ?FiscalResult
    {
        return null;
    }

    private function send(FiscalSubmission $submission, FiscalSettings $settings, ?int $originalInvoiceNo): FiscalResult
    {
        $key = $settings->credential('cmc_key');

        if ($key === null) {
            throw new LocalRejection('not_initialized', __('fiscal.errors.not_initialized'));
        }

        $body = EtimsPayload::build($submission, $settings, $originalInvoiceNo);
        $hash = hash('sha256', (string) json_encode($body));
        $answer = $this->client->post('save_sale', $body, (string) $settings->tin, (string) $settings->branch_code, $key);

        if ($answer === null) {
            return FiscalResult::retry('authority_unavailable', __('fiscal.errors.authority_unavailable'))->sent($hash);
        }

        $code = (string) ($answer['resultCd'] ?? '');

        // KRA already holds this invoice number: ours when the same body was sent before
        // (its answer was lost), else someone else's (never overwritten or guessed).
        if (in_array($code, (array) config('fiscal.etims.duplicate_codes', []), true)) {
            if ($submission->request_hash === $hash) {
                return FiscalResult::accepted(['invoice_number' => (string) $submission->invoice_no, 'recovered' => 'duplicate'])->sent($hash);
            }

            throw new NeedsAttention('duplicate_invoice', __('fiscal.errors.duplicate_invoice', ['number' => $submission->invoice_no]));
        }

        if ($code === '000') {
            $data = (array) ($answer['data'] ?? []);
            $signature = self::text($data['rcptSign'] ?? null);
            $prefix = (string) config('fiscal.etims.qr_prefix');

            return FiscalResult::accepted(array_filter([
                'receipt_number' => self::text($data['rcptNo'] ?? null),
                'total_receipt_number' => self::text($data['totRcptNo'] ?? null),
                'internal_data' => self::text($data['intrlData'] ?? null),
                'receipt_signature' => $signature,
                'control_unit_id' => self::text($data['sdcId'] ?? null),
                'mrc_no' => self::text($data['mrcNo'] ?? null),
                'authority_time' => self::text($data['vsdcRcptPbctDate'] ?? null),
                'invoice_number' => (string) $submission->invoice_no,
                'qr' => $prefix !== '' && $signature !== null ? $prefix.$settings->tin.$settings->branch_code.$signature : null,
            ], fn ($value) => $value !== null))->sent($hash);
        }

        $message = __('fiscal.errors.authority_refused', ['code' => $code, 'message' => mb_substr((string) ($answer['resultMsg'] ?? ''), 0, 200)]);

        return (in_array($code, (array) config('fiscal.etims.retryable_codes', []), true)
            ? FiscalResult::retry('etims_'.$code, $message)
            : FiscalResult::rejected('etims_'.$code, $message))->sent($hash);
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, 255) : null;
    }
}
