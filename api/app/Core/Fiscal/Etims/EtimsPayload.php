<?php

namespace App\Core\Fiscal\Etims;

use App\Core\Fiscal\FiscalBands;
use App\Core\Fiscal\FiscalDocument;
use App\Core\Fiscal\LocalRejection;
use App\Core\Fiscal\Models\FiscalSettings;
use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\MasterData\Taxes\TaxCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * A FiscalDocument as KRA eTIMS OSCU's `saveTrnsSalesOsdc` request: a sale
 * (`rcptTyCd` S) or a credit note (R, for a refund or a void, naming the
 * original's fiscal invoice number in `orgInvcNo`).
 *
 * - Bands: each line's tax type (`taxTyCd`, A to E) is its tax code's
 *   `fiscal_code` (FiscalBands). The rate per band (`taxRtA`..`taxRtE`) is
 *   the rate the till applied on that band's lines; two lines of one band
 *   at different rates stop the document. A band with no line takes the
 *   rate of the company's tax code with that fiscal code in force on the
 *   document's date, else 0 (no amount is reported on it). No rate is
 *   ever made up.
 * - Amounts are VAT inclusive as eTIMS expects: per line, `taxblAmt` and
 *   `totAmt` are the line total, `taxAmt` the line tax, `dcAmt` the
 *   discount, `splyAmt` total + discount and `prc` = splyAmt / qty.
 * - Items: `itemCd` is the item's code (its KRA-registered code once item
 *   registration exists), `itemClsCd` its KRA classification, else the
 *   company's default; the unit codes likewise. A missing one stops the
 *   document with the item's name.
 * - Only KES documents (eTIMS reports in shillings).
 * - Dates are Nairobi time: `salesDt` Ymd, `cfmDt` YmdHis.
 *
 * Numbers leave as JSON numbers with two decimals (the API's format);
 * they are built from integer minor units, never from float arithmetic.
 */
final class EtimsPayload
{
    /** @return array<string, mixed> */
    public static function build(FiscalSubmission $submission, FiscalSettings $settings, ?int $originalInvoiceNo): array
    {
        $document = FiscalDocument::fromArray($submission->payload);
        $data = $document->data;

        if ($data['currency'] !== 'KES') {
            throw new LocalRejection('currency_not_supported', __('fiscal.errors.currency_not_supported', ['currency' => $data['currency']]));
        }

        $config = config('fiscal.etims');
        $bands = FiscalBands::resolve($document, $config['bands']);
        $issued = CarbonImmutable::parse($data['issued_at'])->setTimezone('Africa/Nairobi');
        $confirmed = $issued->format('YmdHis');
        $creditNote = $submission->isCreditNote();

        $rates = [];
        $taxable = array_fill_keys($config['bands'], 0);
        $tax = array_fill_keys($config['bands'], 0);
        $items = [];

        foreach ($data['lines'] as $index => $line) {
            $band = $bands[(int) $line['line_no']]['code'];
            $rate = self::rate($line['tax_rate'] ?? null);

            if ($rate !== null) {
                if (isset($rates[$band]) && ! $rates[$band]->isEqualTo($rate)) {
                    throw new LocalRejection('band_rate_conflict', __('fiscal.errors.band_rate_conflict', ['band' => $band]));
                }

                $rates[$band] = $rate;
            }

            $total = (int) $line['total_minor'];
            $lineTax = (int) $line['tax_minor'];
            $discount = (int) ($line['discount_minor'] ?? 0);
            $supply = $total + $discount;
            $qty = BigDecimal::of((string) $line['qty']);
            $taxable[$band] += $total;
            $tax[$band] += $lineTax;

            $items[] = [
                'itemSeq' => $index + 1,
                'itemCd' => self::required($line['fiscal_item_code'] ?? $line['item_code'] ?? null, 'item_code_missing', $line),
                'itemClsCd' => self::required($line['classification_code'] ?? $settings->setting('default_item_class_code'), 'item_class_missing', $line),
                'itemNm' => mb_substr((string) $line['item_name'], 0, 200),
                'bcd' => isset($line['barcode']) ? mb_substr((string) $line['barcode'], 0, 20) : null,
                'pkgUnitCd' => self::required($line['packaging_code'] ?? $settings->setting('default_packaging_unit_code'), 'unit_code_missing', $line),
                'pkg' => self::number($qty),
                'qtyUnitCd' => self::required($line['unit_code'] ?? $settings->setting('default_quantity_unit_code'), 'unit_code_missing', $line),
                'qty' => self::number($qty),
                'prc' => $qty->isZero() ? 0 : self::number(BigDecimal::ofUnscaledValue($supply, 2)->dividedBy($qty, 2, RoundingMode::HalfUp)),
                'splyAmt' => self::money($supply),
                'dcRt' => $supply === 0 ? 0 : self::number(BigDecimal::of($discount)->multipliedBy(100)->dividedBy($supply, 2, RoundingMode::HalfUp)),
                'dcAmt' => self::money($discount),
                'isrccCd' => null,
                'isrccNm' => null,
                'isrcRt' => null,
                'isrcAmt' => null,
                'taxTyCd' => $band,
                'taxblAmt' => self::money($total),
                'taxAmt' => self::money($lineTax),
                'totAmt' => self::money($total),
            ];
        }

        $body = [
            'tin' => $settings->tin,
            'bhfId' => $settings->branch_code,
            'trdInvcNo' => mb_substr((string) ($data['number'] ?? $submission->invoice_no), 0, 50),
            'invcNo' => $submission->invoice_no,
            'orgInvcNo' => $creditNote ? (int) $originalInvoiceNo : 0,
            'custTin' => self::blankToNull($data['customer']['tin'] ?? null),
            'custNm' => self::blankToNull(isset($data['customer']['name']) ? mb_substr((string) $data['customer']['name'], 0, 60) : null),
            'salesTyCd' => $config['sales_type'],
            'rcptTyCd' => $config['receipt_types'][$submission->document_type],
            'pmtTyCd' => $config['payment_types'][$data['payment_type']] ?? $config['payment_types']['other'],
            'salesSttsCd' => $config['sales_status'],
            'cfmDt' => $confirmed,
            'salesDt' => $issued->format('Ymd'),
            'stockRlsDt' => $confirmed,
            'cnclReqDt' => null,
            'cnclDt' => null,
            'rfdDt' => $creditNote ? $confirmed : null,
            'rfdRsnCd' => $creditNote ? $config['refund_reason'] : null,
            'totItemCnt' => count($items),
        ];

        foreach ($config['bands'] as $band) {
            $body["taxblAmt{$band}"] = self::money($taxable[$band]);
        }

        foreach ($config['bands'] as $band) {
            $body["taxRt{$band}"] = self::number($rates[$band] ?? self::companyRate($submission->company_id, $band, $issued));
        }

        foreach ($config['bands'] as $band) {
            $body["taxAmt{$band}"] = self::money($tax[$band]);
        }

        return $body + [
            'totTaxblAmt' => self::money(array_sum($taxable)),
            'totTaxAmt' => self::money(array_sum($tax)),
            'totAmt' => self::money(array_sum($taxable)),
            'prchrAcptcYn' => 'N',
            'remark' => null,
            'regrId' => mb_substr((string) ($data['cashier']['id'] ?? 'system'), 0, 20),
            'regrNm' => mb_substr((string) ($data['cashier']['name'] ?? 'System'), 0, 60),
            'modrId' => mb_substr((string) ($data['cashier']['id'] ?? 'system'), 0, 20),
            'modrNm' => mb_substr((string) ($data['cashier']['name'] ?? 'System'), 0, 60),
            'receipt' => [
                'custTin' => self::blankToNull($data['customer']['tin'] ?? null),
                'custMblNo' => null,
                'rptNo' => null,
                'rcptPbctDt' => $confirmed,
                'trdeNm' => null,
                'adrs' => null,
                'topMsg' => null,
                'btmMsg' => null,
                'prchrAcptcYn' => 'N',
            ],
            'itemList' => $items,
        ];
    }

    private static function rate(mixed $rate): ?BigDecimal
    {
        if ($rate === null || $rate === '') {
            return null;
        }

        return BigDecimal::of((string) $rate)->toScale(2, RoundingMode::HalfUp);
    }

    /** The rate of the company's tax code carrying $band on $at, or 0 when none has one. */
    private static function companyRate(string $companyId, string $band, CarbonImmutable $at): BigDecimal
    {
        $code = TaxCode::query()->where('company_id', $companyId)->where('fiscal_code', $band)->whereNull('archived_at')->with('company')->orderBy('code')->first();
        $rate = $code?->rateOn($at)?->rate;

        return $rate === null ? BigDecimal::zero() : BigDecimal::of((string) $rate)->toScale(2, RoundingMode::HalfUp);
    }

    private static function required(mixed $value, string $reason, array $line): string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new LocalRejection($reason, __("fiscal.errors.{$reason}", ['item' => (string) $line['item_name']]));
        }

        return mb_substr(trim((string) $value), 0, 20);
    }

    private static function money(int $minor): float
    {
        return self::number(BigDecimal::ofUnscaledValue($minor, 2));
    }

    private static function number(BigDecimal $value): float
    {
        return (float) (string) $value->toScale(2, RoundingMode::HalfUp);
    }

    private static function blankToNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
