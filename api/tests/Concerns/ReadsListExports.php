<?php

namespace Tests\Concerns;

use App\Core\Audit\Auditor;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Exports\ListExport;
use App\Core\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Reading list responses and exports in tests (EXP-01): row ids of a JSON
 * list, CSV rows, Excel cells and the HTML handed to dompdf for a PDF.
 */
trait ReadsListExports
{
    /** @return list<string> the ids of a JSON list's rows, in order */
    protected function listIds(string $url, array $headers): array
    {
        return array_column($this->getJson($url, $headers)->assertOk()->json('data'), 'id');
    }

    /** @return list<list<string>> */
    protected function csvRows(TestResponse $response): array
    {
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}", $content, 'CSV starts with a UTF-8 BOM');

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, substr($content, 3));
        rewind($handle);
        $rows = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /** @return list<list<string>> */
    protected function xlsxRows(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($path, $response->streamedContent());
        $reader = new Reader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($cell) => (string) $cell->getValue(), $row->cells);
            }

            break;
        }

        $reader->close();
        unlink($path);

        return $rows;
    }

    /** The HTML ListExport hands to dompdf while $run runs. */
    protected function capturePdfHtml(callable $run): string
    {
        $captured = new \ArrayObject;
        $this->app->bind(ListExport::class, fn ($app) => new class($app->make(TenantContext::class), $app->make(Auditor::class), $app->make(CurrencyDecimals::class), $captured) extends ListExport
        {
            public function __construct($tenants, $auditor, $decimals, private \ArrayObject $captured)
            {
                parent::__construct($tenants, $auditor, $decimals);
            }

            protected function renderPdf(string $html): string
            {
                $this->captured->append($html);

                return parent::renderPdf($html);
            }
        });

        try {
            $content = $run();
            $this->assertStringStartsWith('%PDF-', $content);
        } finally {
            $this->app->offsetUnset(ListExport::class);
        }

        $this->assertCount(1, $captured);

        return $captured[0];
    }

    /** A JSON-accepting GET (an export refused before streaming answers JSON). */
    protected function refusedExport(string $url, array $headers): TestResponse
    {
        return $this->get($url, [...$headers, 'Accept' => 'application/json']);
    }
}
