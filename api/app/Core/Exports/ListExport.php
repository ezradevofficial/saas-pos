<?php

namespace App\Core\Exports;

use App\Core\Audit\Auditor;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Http\ApiException;
use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\Items\Http\Resources\HidesFields;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports a list (EXP-01): every row matching the request's filters,
 * search and sort, no paging, as CSV, Excel or PDF.
 *
 * - Values come from the list's own API resource rendered for the
 *   requesting user, so fields hidden by field rules never appear, and a
 *   column built from a hidden field is dropped (RBAC-05).
 * - Headers and values in the user's language (ExportValues).
 * - Streamed after the response starts, so rows are read inside the
 *   tenant's own context (TEN-01, RLS), in chunks.
 * - Audited as the list's `<module>.<resource>.export` with
 *   {format, rows, columns, filters} (AUD-01).
 * - PDF is capped at PDF_MAX_ROWS (422 above it).
 */
class ListExport
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    public const PDF_MAX_ROWS = 2000;

    private const CHUNK = 500;

    private const CONTENT_TYPES = [
        'csv' => 'text/csv; charset=UTF-8',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf' => 'application/pdf',
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
        private readonly CurrencyDecimals $decimals,
    ) {}

    /**
     * @param  FormRequest&ListsRecords  $request  a validated list request asking for an export
     * @param  Builder<Model>  $query  the list's query, filtered and sorted
     */
    public function download(FormRequest $request, Builder $query): StreamedResponse
    {
        $list = $request->list();
        $format = $request->exportFormat();
        $tenantId = $this->tenants->require();
        $columns = $this->visibleColumns($request, $list, $request->exportColumns());
        $filters = $request->listFilters();
        $rows = (clone $query)->toBase()->getCountForPagination();

        if ($format === 'pdf' && $rows > self::PDF_MAX_ROWS) {
            $message = __('core.list.pdf_too_many_rows');

            throw new ApiException(422, 'export_too_many_rows', $message, ['format' => [$message]]);
        }

        $values = $this->values();
        $headers = array_map(fn (ListColumn $column) => __($column->label), $columns);
        $title = $list->title($filters);
        $summary = $list->filterSummary($filters, $values);

        $this->auditor->record($list->auditAction(), null, null, [
            'format' => $format,
            'rows' => $rows,
            'columns' => array_map(fn (ListColumn $column) => $column->key, $columns),
            'filters' => $filters,
        ]);

        $query = (clone $query)->with($list->exportRelations());
        $locale = app()->getLocale();
        $filename = $list->name().'-'.now()->setTimezone($values->timezone)->format('Y-m-d').'.'.$format;

        return response()->streamDownload(function () use ($tenantId, $locale, $format, $query, $list, $request, $columns, $values, $headers, $title, $summary) {
            // The body is sent after the request's tenant context may be
            // gone: read the rows in the tenant's own context (RLS).
            $this->tenants->run($tenantId, function () use ($locale, $format, $query, $list, $request, $columns, $values, $headers, $title, $summary) {
                $previous = app()->getLocale();
                app()->setLocale($locale);

                try {
                    $records = $this->records($query, $list, $request, $columns, $values);

                    match ($format) {
                        'csv' => $this->csv($headers, $records),
                        'xlsx' => $this->xlsx($title, $headers, $records),
                        'pdf' => $this->pdf($title, $summary, $values, $headers, $records),
                    };
                } finally {
                    app()->setLocale($previous);
                }
            });
        }, $filename, ['Content-Type' => self::CONTENT_TYPES[$format]]);
    }

    /**
     * The asked-for columns, in order, without those built from a field
     * the user's field rules hide.
     *
     * @param  list<string>  $keys
     * @return list<ListColumn>
     */
    public function visibleColumns(Request $request, ListDefinition $list, array $keys): array
    {
        $hidden = HidesFields::hidden($request, $list->fieldRules());
        $sources = $list->fieldSources();
        $byKey = [];

        foreach ($list->columns() as $column) {
            $byKey[$column->key] = $column;
        }

        $visible = [];

        foreach ($keys as $key) {
            $column = $byKey[$key] ?? null;

            if ($column === null) {
                continue;
            }

            $blocked = array_filter($column->fields, fn (string $field) => in_array($field, $hidden, true)
                || array_intersect($sources[$field] ?? [], $hidden) !== []);

            if ($blocked === []) {
                $visible[] = $column;
            }
        }

        return $visible;
    }

    /**
     * One list of display strings per record, read in chunks.
     *
     * @param  list<ListColumn>  $columns
     * @return iterable<list<string>>
     */
    private function records(Builder $query, ListDefinition $list, Request $request, array $columns, ExportValues $values): iterable
    {
        foreach ($query->lazy(self::CHUNK) as $model) {
            $row = $list->resolve($model, $request);

            yield array_map(
                fn (ListColumn $column) => array_diff($column->fields, array_keys($row)) === [] ? $column->valueFor($row, $model, $values) : '',
                $columns,
            );
        }
    }

    private function values(): ExportValues
    {
        $companies = Company::query()->orderBy('created_at')->orderBy('id')->pluck('timezone', 'id')->filter()->all();

        return new ExportValues(
            app()->getLocale(),
            reset($companies) ?: 'UTC',
            $companies,
            $this->decimals,
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<list<string>>  $records
     */
    private function csv(array $headers, iterable $records): void
    {
        $out = fopen('php://output', 'w');
        // A UTF-8 byte order mark: Excel then reads accents correctly.
        fwrite($out, "\u{FEFF}");
        fputcsv($out, array_map(SpreadsheetCell::safe(...), $headers), escape: '');

        foreach ($records as $record) {
            fputcsv($out, array_map(SpreadsheetCell::safe(...), $record), escape: '');
        }

        fclose($out);
    }

    /**
     * Written to a temporary file (an xlsx is a zip, finished on close),
     * then streamed. Every value is a string cell: never a formula.
     *
     * @param  list<string>  $headers
     * @param  iterable<list<string>>  $records
     */
    private function xlsx(string $title, array $headers, iterable $records): void
    {
        $path = tempnam(sys_get_temp_dir(), 'list-export-');

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(SpreadsheetCell::sheetName($title));
            $sheet->setSheetView(new SheetView(freezeRow: 2));

            $bold = new Style(fontBold: true);
            $writer->addRow(new Row(array_map(fn (string $header) => new StringCell($header, $bold), $headers)));

            foreach ($records as $record) {
                $writer->addRow(new Row(array_map(fn (string $value) => new StringCell($value), $record)));
            }

            $writer->close();
            readfile($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * A4 landscape, black on white: title, filter summary, when it was
     * generated, the table (header repeated on each page) and page numbers.
     * DejaVu Sans (bundled with dompdf) renders French accents.
     *
     * @param  array<string, string>  $summary
     * @param  list<string>  $headers
     * @param  iterable<list<string>>  $records
     */
    private function pdf(string $title, array $summary, ExportValues $values, array $headers, iterable $records): void
    {
        $e = fn (string $text) => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="'.$e($values->locale).'"><head><meta charset="utf-8"><title>'.$e($title).'</title><style>'
            .'@page { margin: 28pt 28pt 36pt 28pt; }'
            .'body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #000; background: #fff; }'
            .'h1 { font-size: 13pt; font-weight: bold; margin: 0 0 4pt 0; }'
            .'p { margin: 0 0 2pt 0; }'
            .'table { width: 100%; border-collapse: collapse; margin-top: 8pt; }'
            .'th, td { border-bottom: 0.5pt solid #000; padding: 3pt 4pt; text-align: left; vertical-align: top; }'
            .'th { font-weight: bold; border-bottom-width: 1pt; }'
            .'thead { display: table-header-group; }'
            .'tr { page-break-inside: avoid; }'
            .'</style></head><body>';
        $html .= '<h1>'.$e($title).'</h1>';

        foreach ($summary as $label => $value) {
            $html .= '<p>'.$e($label).': '.$e($value).'</p>';
        }

        $html .= '<p>'.$e(__('core.list.generated_at', ['date' => $values->now()])).'</p>';
        $html .= '<table><thead><tr>';

        foreach ($headers as $header) {
            $html .= '<th>'.$e($header).'</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($records as $record) {
            $html .= '<tr>';

            foreach ($record as $value) {
                $html .= '<td>'.nl2br($e($value)).'</td>';
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table></body></html>';

        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setTempDir(sys_get_temp_dir());

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(
            $canvas->get_width() - 110,
            $canvas->get_height() - 24,
            __('core.list.page_of', ['page' => '{PAGE_NUM}', 'pages' => '{PAGE_COUNT}']),
            $font,
            8,
            [0, 0, 0],
        );

        echo $pdf->output();
    }
}
