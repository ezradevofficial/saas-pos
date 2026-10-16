<?php

namespace App\Core\Platform\Reports\Http;

use App\Core\Currency\Money;
use App\Core\Platform\PlatformAuditor;
use App\Core\Platform\Reports\PlatformReports;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADM-06: the reports page (one summary) and each report as CSV. Amounts
 * in CSV are decimals in their currency, next to the currency code.
 * Downloads are audited in the platform chain.
 */
class PlatformReportsController
{
    public const REPORTS = ['mrr', 'revenue', 'trials', 'churn', 'usage'];

    public function __construct(
        private readonly PlatformReports $reports,
        private readonly PlatformAuditor $audit,
    ) {}

    public function summary(ReportsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->summary($request->from(), $request->to())]);
    }

    public function csv(ReportsRequest $request, string $report): StreamedResponse
    {
        $from = $request->from();
        $to = $request->to();
        $money = fn (array $row) => Money::ofMinor((int) $row['amount_minor'], $row['currency'])->toDecimalString();

        [$header, $rows] = match ($report) {
            'mrr' => [['section', 'key', 'currency', 'accounts', 'amount'], (function () use ($money) {
                $mrr = $this->reports->mrr();
                $out = [];

                foreach (['by_currency' => 'currency', 'by_country' => 'country', 'by_plan' => 'plan', 'by_module' => 'module'] as $part => $section) {
                    foreach ($mrr[$part] as $row) {
                        $out[] = [$section, $row['key'], $row['currency'], $row['accounts'] ?? '', $money($row)];
                    }
                }

                return $out;
            })()],
            'revenue' => [['section', 'key', 'currency', 'amount'], (function () use ($from, $to, $money) {
                $revenue = $this->reports->revenue($from, $to);
                $out = [];

                foreach (['by_currency' => 'currency', 'by_country' => 'country', 'by_module' => 'module', 'by_plan' => 'plan'] as $part => $section) {
                    foreach ($revenue[$part] as $row) {
                        $out[] = [$section, $row['key'], $row['currency'], $money($row)];
                    }
                }

                return $out;
            })()],
            'trials' => [['country', 'started', 'converted'], array_map(fn ($r) => [$r['country'], $r['started'], $r['converted']], $this->reports->trials($from, $to)['by_country'])],
            'churn' => [['currency', 'accounts', 'mrr_lost'], array_map(fn ($r) => [$r['currency'], $r['accounts'], $money($r)], $this->reports->churn($from, $to)['mrr_lost'])],
            'usage' => [['unit', 'tenants', 'total'], array_map(fn ($r) => [$r['unit'], $r['tenants'], $r['total']], $this->reports->usage($to))],
            default => abort(404),
        };

        $this->audit->record('platform.report.export', null, null, ['report' => $report, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => count($rows)]);
        $name = "{$report}-{$from->toDateString()}-{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, $header, escape: '');

            foreach ($rows as $row) {
                // Cells never start a formula (CSV injection).
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'".$v : $v, $row), escape: '');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
