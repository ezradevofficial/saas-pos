<?php

namespace App\Core\DataExport;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * EXP-02: builds the zip of a full tenant export, in the current tenant's
 * context on the runtime connection (row-level security on; TEN-01).
 *
 * - `tables/{table}.jsonl`: one JSON object per row, one file per table
 *   (TenantTables), columns in table order, denied columns left out
 *   (ExportColumns). PostgreSQL renders each row as JSON (row_to_json), so
 *   numbers, money in minor units and exchange rates keep their exact
 *   digits. Rows are read in primary-key order, `chunk` at a time (keyset
 *   paging), never a whole table at once, and each query also filters on
 *   the tenant as a second guard behind row-level security.
 * - `files/...`: the tenant's media (item images, brand assets, custom
 *   field and custom form files, approval attachments): every file under
 *   `tenants/{tenant}/` on the media disk except earlier exports.
 * - `manifest.json`: tables with row counts and columns, the files with
 *   their size and SHA-256, the schema version (the last migration), when
 *   it was made, and the tenant's id and name.
 *
 * Work files live in a private temporary directory, removed afterwards.
 */
class TenantExportBuilder
{
    public const FORMAT_VERSION = 1;

    public const MEDIA_DISK = 'media';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly TenantTables $tables,
    ) {}

    /**
     * Build the zip in a new temporary directory. The caller moves the zip
     * and then calls cleanup() with the directory.
     *
     * @return array{dir: string, zip: string, tables: int, rows: int, files: int}
     */
    public function build(string $exportId): array
    {
        $tenantId = $this->tenants->require();
        $dir = storage_path('app/private/tenant-exports/'.Str::uuid7());
        File::ensureDirectoryExists($dir.'/tables', 0700);
        File::ensureDirectoryExists($dir.'/files', 0700);

        try {
            $manifest = [
                'format' => 'tenant-export',
                'format_version' => self::FORMAT_VERSION,
                'export_id' => $exportId,
                'generated_at' => CarbonImmutable::now()->utc()->toIso8601ZuluString(),
                'schema_version' => $this->schemaVersion(),
                'tenant' => $this->tenant($tenantId),
                'tables' => [],
                'files' => [],
                'omitted' => [
                    'tables' => $this->tables->omitted(),
                    'columns' => array_keys(ExportColumns::DENIED),
                ],
            ];
            $entries = [];
            $rows = 0;

            foreach ($this->tables->all() as $table) {
                $file = "tables/{$table['name']}.jsonl";
                $count = $this->writeTable($tenantId, $table, "{$dir}/{$file}");
                $rows += $count;
                $entries[$file] = "{$dir}/{$file}";
                $manifest['tables'][] = [
                    'name' => $table['name'],
                    'file' => $file,
                    'rows' => $count,
                    'columns' => $table['columns'],
                    'omitted_columns' => $table['omitted'],
                ];
            }

            $media = $this->copyMedia($tenantId, $dir);
            $entries = [...$entries, ...$media];

            foreach ($entries as $name => $path) {
                $manifest['files'][] = ['path' => $name, 'size' => filesize($path), 'sha256' => hash_file('sha256', $path)];
            }

            $zipPath = "{$dir}/export.zip";
            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('The export zip could not be created.');
            }

            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            foreach ($entries as $name => $path) {
                $zip->addFile($path, $name);
            }

            if (! $zip->close()) {
                throw new RuntimeException('The export zip could not be written.');
            }

            return ['dir' => $dir, 'zip' => $zipPath, 'tables' => count($manifest['tables']), 'rows' => $rows, 'files' => count($media)];
        } catch (\Throwable $e) {
            $this->cleanup($dir);

            throw $e;
        }
    }

    public function cleanup(string $dir): void
    {
        File::deleteDirectory($dir);
    }

    /** @param array{name: string, key: string, columns: list<string>, primary: list<string>} $table */
    private function writeTable(string $tenantId, array $table, string $path): int
    {
        $db = DB::connection(TenantContext::CONNECTION);
        $chunk = max(1, (int) config('data_export.chunk', 1000));
        $q = fn (string $name) => '"'.str_replace('"', '""', $name).'"';

        $primary = $table['primary'];
        $pkList = implode(', ', array_map($q, $primary));
        // Keyset columns come back alongside the row, under names no table uses.
        $keys = implode(', ', array_map(fn (string $c, int $i) => 'x.'.$q($c)." as \"__k{$i}\"", $primary, array_keys($primary)));
        // The primary key is always selected for paging, then dropped if it is not exported.
        $inner = implode(', ', array_map($q, array_values(array_unique([...$table['columns'], ...$primary]))));
        $dropped = array_values(array_diff($primary, $table['columns']));
        $json = $dropped === []
            ? 'row_to_json(x)::text'
            : '(to_jsonb(x) - array['.implode(', ', array_map(fn ($c) => "'".str_replace("'", "''", $c)."'", $dropped)).'])::text';

        $handle = fopen($path, 'xb');

        if ($handle === false) {
            throw new RuntimeException("The export file for [{$table['name']}] could not be created.");
        }

        $count = 0;
        $after = null;

        try {
            do {
                $bindings = [$tenantId];
                $where = "{$q($table['key'])} = ?::uuid";

                if ($after !== null) {
                    $where .= " and ({$pkList}) > (".implode(', ', array_fill(0, count($after), '?')).')';
                    array_push($bindings, ...$after);
                }

                $sql = "select {$json} as \"__line\", {$keys} from (select {$inner} from public.{$q($table['name'])} where {$where} order by {$pkList} limit {$chunk}) x order by {$pkList}";
                $batch = $db->select($sql, $bindings);

                foreach ($batch as $row) {
                    fwrite($handle, $row->__line."\n");
                }

                $count += count($batch);

                if ($batch !== []) {
                    $last = (array) end($batch);
                    $after = array_map(fn (int $i) => $last["__k{$i}"], array_keys($primary));
                }
            } while (count($batch) === $chunk);
        } finally {
            fclose($handle);
        }

        return $count;
    }

    /** @return array<string, string> zip entry name => local path */
    private function copyMedia(string $tenantId, string $dir): array
    {
        $disk = Storage::disk(self::MEDIA_DISK);
        $prefix = "tenants/{$tenantId}/";
        $exports = "{$prefix}exports/";
        $entries = [];

        foreach ($disk->allFiles(rtrim($prefix, '/')) as $path) {
            if (! str_starts_with($path, $prefix) || str_starts_with($path, $exports)) {
                continue;
            }

            $relative = substr($path, strlen($prefix));

            if ($relative === '' || str_contains($relative, '..')) {
                continue;
            }

            $local = "{$dir}/files/{$relative}";
            File::ensureDirectoryExists(dirname($local), 0700);
            $in = $disk->readStream($path);
            $out = fopen($local, 'xb');

            if ($in === null || $out === false) {
                throw new RuntimeException("The media file [{$relative}] could not be copied.");
            }

            try {
                stream_copy_to_stream($in, $out);
            } finally {
                fclose($in);
                fclose($out);
            }

            $entries["files/{$relative}"] = $local;
        }

        return $entries;
    }

    /** @return array{id: string, name: string} */
    private function tenant(string $tenantId): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        return ['id' => (string) $tenant->id, 'name' => (string) $tenant->name];
    }

    private function schemaVersion(): ?string
    {
        $row = DB::connection(TenantContext::CONNECTION)->selectOne('select migration from migrations order by id desc limit 1');

        return $row?->migration;
    }
}
