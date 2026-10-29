<?php

namespace Modules\Accounting\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Exports\ListExport;
use App\Core\Exports\SpreadsheetCell;
use App\Core\Fiscal\FiscalQueue;
use App\Core\Http\ApiException;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\Accounting\Fiscal\AccountingFiscalSource;
use Modules\Accounting\Http\Requests\TaxReturnRequest;
use Modules\Accounting\Reports\TaxReturn;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ACC-36: the tax return of a company for a period (VAT return summary in Kenya, TVA declaration in the DR Congo), the
 * documents behind each row, and its CSV export (audited, within the per-user export allowance, EXP-01). Permission
 * `accounting.report.view` at the company.
 */
class TaxReturnController
{
    private const DETAIL_LIMIT = 50;

    public function __construct(
        private readonly TaxReturn $returns,
        private readonly Auditor $auditor,
        private readonly ListExport $exports,
        private readonly TenantContext $tenants,
        private readonly CurrencyDecimals $decimals,
        private readonly FiscalQueue $queue,
    ) {}

    public function show(TaxReturnRequest $request, Company $company): JsonResponse
    {
        return response()->json(['data' => $this->returns->summary($company, $request->validated('from'), $request->validated('to'))]);
    }

    /** The documents and journals behind one row (or the whole period), a page at a time. */
    public function entries(TaxReturnRequest $request, Company $company): JsonResponse
    {
        $page = (int) $request->validated('page', 1);
        $perPage = (int) $request->validated('per_page', self::DETAIL_LIMIT);
        $result = $this->returns->entries($company, $request->validated('from'), $request->validated('to'), $request->validated('direction'), $request->validated('tax_code'), $request->validated('rate'), $page, $perPage);

        $data = array_map(fn (array $entry) => [
            ...$entry,
            'fiscal' => $entry['document_id'] !== null && $entry['direction'] === TaxReturn::OUTPUT
                ? $this->queue->statusFor(AccountingFiscalSource::KEY, AccountingFiscalSource::typeOf($entry['document_kind']), $entry['document_id'])
                : null,
        ], $result['data']);

        return response()->json(['data' => $data, 'meta' => ['total' => $result['total'], 'page' => $page, 'per_page' => $perPage, 'currency' => $company->base_currency]]);
    }

    /** Every line of the return as CSV: audited as `accounting.tax_return.export`. */
    public function export(TaxReturnRequest $request, Company $company): StreamedResponse
    {
        if ($request->user()?->support_grant_id !== null) {
            throw new ApiException(403, 'support_route_refused', __('platform.support.route_refused'));
        }

        $this->exports->throttle($request);
        $from = $request->validated('from');
        $to = $request->validated('to');
        $tenantId = $this->tenants->require();
        $locale = app()->getLocale();
        $currency = (string) $company->base_currency;
        $places = $this->decimals->for($currency);
        $names = TaxCode::query()->where('company_id', $company->id)->get()->keyBy('id');
        $headers = array_map(fn (string $key) => __("accounting.tax_return.csv.{$key}"), ['section', 'tax_code', 'tax_code_name', 'kind', 'rate', 'document', 'number', 'date', 'source', 'taxable', 'tax', 'currency']);

        $summary = $this->returns->summary($company, $from, $to);
        $this->auditor->record('accounting.tax_return.export', null, null, [
            'company_id' => $company->id, 'from' => $from, 'to' => $to, 'format' => 'csv', 'rows' => array_sum(array_column($summary['rows'], 'entries')),
            'output_minor' => $summary['totals']['output_minor'], 'input_minor' => $summary['totals']['input_minor'],
        ]);

        $amount = function (?int $minor) use ($places): string {
            if ($minor === null) {
                return '';
            }

            $digits = str_pad((string) abs($minor), $places + 1, '0', STR_PAD_LEFT);

            return ($minor < 0 ? '-' : '').($places > 0 ? substr($digits, 0, -$places).'.'.substr($digits, -$places) : $digits);
        };

        return response()->streamDownload(function () use ($tenantId, $locale, $company, $from, $to, $headers, $names, $amount, $currency) {
            $this->tenants->run($tenantId, function () use ($locale, $company, $from, $to, $headers, $names, $amount, $currency) {
                $previous = app()->getLocale();
                app()->setLocale($locale);

                try {
                    $out = fopen('php://output', 'w');
                    fwrite($out, "\u{FEFF}");
                    fputcsv($out, array_map(SpreadsheetCell::safe(...), $headers), escape: '');
                    $this->returns->walk($company, $from, $to, function (array $entry) use ($out, $names, $amount, $currency) {
                        $code = $entry['tax_code_id'] === null ? null : $names->get($entry['tax_code_id']);
                        fputcsv($out, array_map(SpreadsheetCell::safe(...), [
                            __("accounting.tax_return.sections.{$entry['direction']}"),
                            $code?->code ?? '',
                            $code?->name ?? '',
                            $code === null ? '' : __("accounting.tax_return.kinds.{$code->kind}"),
                            $entry['rate'] ?? '',
                            $entry['document_kind'] === null ? __('accounting.tax_return.csv.journal') : __("accounting.document_kinds.{$entry['document_kind']}"),
                            $entry['document_number'] ?? $entry['journal_number'] ?? '',
                            $entry['date'],
                            $entry['source_type'] ?? '',
                            $amount($entry['taxable_minor']),
                            $amount($entry['tax_minor']),
                            $currency,
                        ]), escape: '');
                    });
                    fclose($out);
                } finally {
                    app()->setLocale($previous);
                }
            });
        }, 'tax-return-'.$from.'-'.$to.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
