<?php

namespace Tests\Concerns;

use App\Core\Identity\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * IMP-01..IMP-03: files for import tests, written in the test (CSV with or
 * without a BOM, XLSX), and the wizard's calls.
 */
trait BuildsImports
{
    /** @param list<list<string|int|float|null>> $rows header row first */
    protected function csvFile(array $rows, bool $bom = false, string $separator = ',', string $name = 'import.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'test-import-');
        $handle = fopen($path, 'w');

        if ($bom) {
            fwrite($handle, "\xEF\xBB\xBF");
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row, $separator, escape: '');
        }

        fclose($handle);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /** @param list<list<mixed>> $rows header row first */
    protected function xlsxFile(array $rows, string $name = 'import.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'test-import-').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    protected function upload(string $importer, UploadedFile $file, ?User $user = null): TestResponse
    {
        return $this->post('/api/v1/imports', ['importer' => $importer, 'file' => $file], [...$this->headersFor($user), 'Accept' => 'application/json']);
    }

    /** Upload, set the mode, dry run and import; the import as the API shows it after. */
    protected function importFile(string $importer, UploadedFile $file, string $mode = 'create', ?User $user = null): array
    {
        $id = $this->upload($importer, $file, $user)->assertCreated()->json('data.id');

        if ($mode !== 'create') {
            $this->patchJson("/api/v1/imports/{$id}", ['mode' => $mode], $this->headersFor($user))->assertOk();
        }

        $this->postJson("/api/v1/imports/{$id}/dry-run", [], $this->headersFor($user))->assertStatus(202);
        $this->postJson("/api/v1/imports/{$id}/run", [], $this->headersFor($user))->assertStatus(202);

        return $this->getJson("/api/v1/imports/{$id}", $this->headersFor($user))->assertOk()->json('data');
    }
}
