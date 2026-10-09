<?php

namespace App\Core\Exports;

use App\Core\Audit\Auditor;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Http\ApiException;
use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
 * - PDF is capped at PDF_MAX_ROWS (422 above it): dompdf lays out the
 *   whole document in memory.
 * - At most 10 exports per user per minute (the `exports` rate limiter).
 * - A column list the user's field rules hide entirely is refused (422).
 */
class ListExport
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    public const PDF_MAX_ROWS = 500;

    /** Rows per table in a PDF: dompdf's layout cost grows with table size. */
    public const PDF_TABLE_ROWS = 100;

    /** The named rate limiter (registered in CoreServiceProvider). */
    public const EXPORT_LIMITER = 'exports';

    public const EXPORTS_PER_MINUTE = 10;

    private const TIME_LIMIT_SECONDS = 300;

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
        $this->throttle($request);
        $columns = $this->visibleColumns($request, $list, $request->exportColumns());

        if ($columns === []) {
            // Every column asked for is built from fields the user can't see (RBAC-05).
            $message = __('core.list.columns_hidden');

            throw new ApiException(422, 'export_no_columns', $message, ['columns' => [$message]]);
        }

        $filters = $request->listFilters();
        $rows = (clone $query)->toBase()->getCountForPagination();

        if ($format === 'pdf' && $rows > self::PDF_MAX_ROWS) {
            $message = __('core.list.pdf_too_many_rows');

            throw new ApiException(422, 'export_too_many_rows', $message, ['format' => [$message]]);
        }

        $values = $this->values();
        $headers = array_map(fn (ListColumn $column) => $column->header(), $columns);
        $title = $list->title($filters);
        $summary = $list->filterSummary($filters, $values);

        $this->auditor->record($list->auditAction(), null, null, [
            'format' => $format,
            'rows' => $rows,
            'columns' => array_map(fn (ListColumn $column) => $column->key, $columns),
            'filters' => $filters,
        ]);

        // The export's own relations replace the list's (no images, no signed URLs per row).
        $query = (clone $query)->setEagerLoads([])->with($list->exportRelations());
        $locale = app()->getLocale();
        $filename = $list->name().'-'.now()->setTimezone($values->timezone)->format('Y-m-d').'.'.$format;

        return response()->streamDownload(function () use ($tenantId, $locale, $format, $query, $list, $request, $columns, $values, $headers, $title, $summary) {
            set_time_limit(self::TIME_LIMIT_SECONDS);

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
        $hidden = $list->hiddenFields($request);
        $byKey = [];

        foreach ($list->columns() as $column) {
            $byKey[$column->key] = $column;
        }

        $visible = [];

        foreach ($keys as $key) {
            $column = $byKey[$key] ?? null;

            if ($column !== null && ! $list->hides($column->fields, $hidden)) {
                $visible[] = $column;
            }
        }

        return $visible;
    }

    /**
     * EXP-01: at most EXPORT_LIMITER's exports per user per minute, any
     * format (429 with Retry-After above it). Other exports (the access
     * review) call it too, so they share the allowance.
     */
    public function throttle(Request $request): void
    {
        $limit = RateLimiter::limiter(self::EXPORT_LIMITER)($request);
        $key = self::EXPORT_LIMITER.'|'.$limit->key;

        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            $seconds = max(1, RateLimiter::availableIn($key));

            throw new ApiException(429, 'too_many_exports', trans_choice('core.list.too_many_exports', $seconds, ['seconds' => $seconds]), headers: ['Retry-After' => $seconds]);
        }

        RateLimiter::hit($key, $limit->decaySeconds);
    }

    /**
     * One list of display strings per record, read in chunks. lazy() pages
     * with LIMIT/OFFSET over the list's stable order (ties by id): fine
     * for today's list sizes; keyset paging can replace it if exports of
     * very large lists get slow.
     *
     * @param  list<ListColumn>  $columns
     * @return iterable<list<string>>
     */
    private function records(Builder $query, ListDefinition $list, Request $request, array $columns, ExportValues $values): iterable
    {
        foreach ($query->lazy(self::CHUNK) as $model) {
            $row = $list->resolve($model, $request);

            yield array_map(
                // A nested field (`custom.<key>`, CF-03) is present when its parent key is.
                fn (ListColumn $column) => array_diff(array_map(fn (string $field) => explode('.', $field, 2)[0], $column->fields), array_keys($row)) === [] ? $column->valueFor($row, $model, $values) : '',
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
        // Removed even when the client goes away mid-download and PHP stops here.
        register_shutdown_function(static fn () => is_file($path) && @unlink($path));

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
        echo $this->renderPdf($this->pdfHtml($title, $summary, $values, $headers, $records));
    }

    /**
     * The PDF's HTML, every value escaped. Cheap for dompdf: fixed table
     * layout, rows in tables of PDF_TABLE_ROWS (the header row repeated on
     * each), rows free to break across pages.
     *
     * @param  array<string, string>  $summary
     * @param  list<string>  $headers
     * @param  iterable<list<string>>  $records
     */
    private function pdfHtml(string $title, array $summary, ExportValues $values, array $headers, iterable $records): string
    {
        $e = fn (string $text) => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="'.$e($values->locale).'"><head><meta charset="utf-8"><title>'.$e($title).'</title><style>'
            .'@page { margin: 28pt 28pt 36pt 28pt; }'
            .'body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #000; background: #fff; }'
            .'h1 { font-size: 13pt; font-weight: bold; margin: 0 0 4pt 0; }'
            .'p { margin: 0 0 2pt 0; }'
            .'table { width: 100%; table-layout: fixed; border-collapse: collapse; margin-top: 6pt; }'
            .'th, td { border-bottom: 0.5pt solid #000; padding: 3pt 4pt; text-align: left; vertical-align: top; word-wrap: break-word; }'
            .'th { font-weight: bold; border-bottom-width: 1pt; }'
            .'</style></head><body>';
        $html .= '<h1>'.$e($title).'</h1>';

        foreach ($summary as $label => $value) {
            $html .= '<p>'.$e($label).': '.$e($value).'</p>';
        }

        $html .= '<p>'.$e(__('core.list.generated_at', ['date' => $values->now()])).'</p>';
        $head = '<table><thead><tr>'.implode('', array_map(fn (string $header) => '<th>'.$e($header).'</th>', $headers)).'</tr></thead><tbody>';
        $html .= $head;
        $inTable = 0;

        foreach ($records as $record) {
            if ($inTable === self::PDF_TABLE_ROWS) {
                $html .= '</tbody></table>'.$head;
                $inTable = 0;
            }

            $html .= '<tr>'.implode('', array_map(fn (string $value) => '<td>'.nl2br($e($value)).'</td>', $record)).'</tr>';
            $inTable++;
        }

        return $html.'</tbody></table></body></html>';
    }

    /**
     * A4 landscape with page numbers. Hardened: no remote files, no PHP,
     * no JavaScript, local files confined to an empty directory of its own.
     */
    protected function renderPdf(string $html): string
    {
        $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'list-exports-pdf';

        if (! is_dir($sandbox)) {
            @mkdir($sandbox, 0700, true);
        }

        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot($sandbox);
        $options->setTempDir($sandbox);

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

        return (string) $pdf->output();
    }
}
