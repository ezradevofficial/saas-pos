<?php

namespace Modules\Procurement\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Exports\ListExport;
use App\Core\Exports\ExportValues;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Modules\Procurement\Documents\SupplierPerformance;
use Modules\Procurement\Http\Requests\PerformanceRequest;
use Modules\Procurement\Support\Places;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PRC-18: supplier performance over a period, from the documents at the
 * reader's places (RBAC-04); `?format=csv` exports it (EXP-01, audited as
 * `procurement.performance.export`, rate-limited like list exports).
 */
class PerformanceController
{
    public function __invoke(PerformanceRequest $request, SupplierPerformance $performance, Places $places, ListExport $exports, Auditor $auditor): JsonResponse|StreamedResponse
    {
        $to = $request->validated('to', CarbonImmutable::now()->toDateString());
        $from = $request->validated('from', CarbonImmutable::parse($to)->subDays(89)->toDateString());
        $rows = $performance->rows($from, $to, $request->validated('company'), $request->validated('party'),
            fn (Builder $query, string $column) => $places->filter($query, $request->user(), 'procurement.performance.view', $column));

        if ($request->validated('format') !== 'csv') {
            return response()->json(['data' => $rows, 'meta' => ['from' => $from, 'to' => $to]]);
        }

        $exports->throttle($request);
        $auditor->record('procurement.performance.export', null, null, ['from' => $from, 'to' => $to, 'rows' => count($rows)]);
        $values = new ExportValues(app()->getLocale(), 'UTC', [], app(CurrencyDecimals::class));
        $percent = fn (?int $bp) => $bp === null ? null : number_format($bp / 100, 2, '.', '');

        return response()->streamDownload(function () use ($rows, $values, $percent) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map(fn (string $key) => __("procurement.performance.{$key}"), ['supplier', 'company', 'orders', 'receipts', 'on_time', 'quality', 'lead_time', 'price_variance', 'price_variance_percent']));

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['party']['name'], $row['company']['name'], $row['orders'], $row['receipts'],
                    $percent($row['on_time_bp']), $percent($row['quality_bp']), $row['lead_time_days'],
                    $values->money($row['price_variance']), $percent($row['price_variance_bp']),
                ]);
            }

            fclose($out);
        }, "supplier-performance-{$from}-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
