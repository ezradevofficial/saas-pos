<?php

namespace App\Core\DataExport;

use App\Core\Files\TenantFiles;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * EXP-02: builds the zip of a full tenant export, in the current tenant's
 * context on the runtime connection (row-level security on; TEN-01).
 *
 * - `tables/{table}.jsonl`: one JSON object per row, one file per table
 *   (TenantTables), columns in table order, denied columns left out and
 *   transformed ones rewritten (ExportColumns). PostgreSQL renders each row
 *   as JSON (row_to_json), so money and exchange rates keep their exact
 *   digits. Each table is read through one server-side cursor in its own
 *   REPEATABLE READ READ ONLY transaction: a consistent snapshot of the
 *   table, one scan, `chunk` rows per fetch, never the whole table in
 *   memory. A read-only transaction takes no transaction id, so it does not
 *   hold back device sync (ADR 004). The query also filters on the tenant,
 *   a second guard behind row-level security.
 * - `files/...`: the tenant's media: every file under `tenants/{tenant}/`
 *   on the media disk except exports. On a local disk the zip reads each
 *   file in place; on an object store files go through a bounded set of
 *   temporary copies, flushed into the zip as they accumulate.
 * - `manifest.json`: tables with row counts and columns, the files with
 *   their size and SHA-256, the schema version (the last migration), when
 *   it was made, and the tenant's id and name.
 * - Larger than `data_export.max_bytes` (before compression): refused with
 *   ExportTooLarge.
 *
 * Work files live in a private temporary directory under TEMP_ROOT,
 * removed afterwards; sweepTemporary() removes any a killed worker left.
 */
class TenantExportBuilder
{
    public const FORMAT_VERSION = 1;

    public const TEMP_ROOT = 'app/private/tenant-exports';

    /** Temporary media copies flushed into the zip once they reach this size or count. */
    private const FLUSH_BYTES = 256 * 1024 * 1024;

    private const FLUSH_FILES = 100;

    private int $bytes = 0;

    private int $maxBytes = 0;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly TenantTables $tables,
    ) {}

    /**
     * Build the zip in a new temporary directory. The caller moves the zip
     * and then calls cleanup() with the directory.
     *
     * @return array{dir: string, zip: string, tables: int, rows: int, files: int}
     *
     * @throws ExportTooLarge
     */
    public function build(string $exportId): array
    {
        $tenantId = $this->tenants->require();
        $dir = storage_path(self::TEMP_ROOT.'/'.Str::uuid7());
        File::ensureDirectoryExists($dir.'/tables', 0700);
        File::ensureDirectoryExists($dir.'/media', 0700);
        $this->bytes = 0;
        $this->maxBytes = max(1, (int) config('data_export.max_bytes'));

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
                    'transformed' => array_keys(ExportColumns::TRANSFORMS),
                ],
            ];
            $zipPath = "{$dir}/export.zip";
            $zip = $this->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL);
            $rows = 0;

            foreach ($this->tables->all() as $table) {
                $file = "tables/{$table['name']}.jsonl";
                $local = "{$dir}/{$file}";
                $count = $this->writeTable($tenantId, $table, $local);
                $rows += $count;
                $zip->addFile($local, $file);
                $manifest['tables'][] = [
                    'name' => $table['name'],
                    'file' => $file,
                    'rows' => $count,
                    'columns' => $table['columns'],
                    'omitted_columns' => $table['omitted'],
                ];
                $manifest['files'][] = ['path' => $file, 'size' => filesize($local), 'sha256' => hash_file('sha256', $local)];
            }

            $media = $this->addMedia($tenantId, $dir, $zip, $zipPath, $manifest['files']);

            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            if (! $zip->close()) {
                throw new RuntimeException('The export zip could not be written.');
            }

            return ['dir' => $dir, 'zip' => $zipPath, 'tables' => count($manifest['tables']), 'rows' => $rows, 'files' => $media];
        } catch (Throwable $e) {
            $this->cleanup($dir);

            throw $e;
        }
    }

    public function cleanup(string $dir): void
    {
        File::deleteDirectory($dir);
    }

    /** Remove work directories older than $seconds: left by a worker killed mid-build. */
    public static function sweepTemporary(int $seconds): int
    {
        $root = storage_path(self::TEMP_ROOT);
        $removed = 0;

        if (! is_dir($root)) {
            return 0;
        }

        foreach (File::directories($root) as $dir) {
            if ((int) filemtime($dir) < time() - $seconds) {
                File::deleteDirectory($dir);
                $removed++;
            }
        }

        return $removed;
    }

    /** @param array{name: string, key: string, columns: list<string>, primary: list<string>} $table */
    private function writeTable(string $tenantId, array $table, string $path): int
    {
        if (! Str::isUuid($tenantId)) {
            throw new RuntimeException('Invalid tenant id.');
        }

        $db = DB::connection(TenantContext::CONNECTION);
        $chunk = max(1, (int) config('data_export.chunk', 1000));
        $q = fn (string $name) => '"'.str_replace('"', '""', $name).'"';

        $select = implode(', ', array_map(function (string $column) use ($table, $q) {
            $transform = ExportColumns::TRANSFORMS["{$table['name']}.{$column}"] ?? null;

            return $transform === null ? "t.{$q($column)}" : "({$transform}) as {$q($column)}";
        }, $table['columns']));
        $order = $table['primary'] === [] ? '' : ' order by '.implode(', ', array_map(fn ($c) => "t.{$q($c)}", $table['primary']));
        // The tenant id is a validated UUID, inlined: DECLARE takes no parameters.
        $sql = "select row_to_json(x)::text as line from (select {$select} from public.{$q($table['name'])} t where t.{$q($table['key'])} = '{$tenantId}'::uuid{$order}) x";

        $handle = fopen($path, 'xb');

        if ($handle === false) {
            throw new RuntimeException("The export file for [{$table['name']}] could not be created.");
        }

        $count = 0;
        $outer = $db->transactionLevel();
        $db->beginTransaction();

        try {
            // A transaction of its own: one consistent snapshot of the table.
            // Nested (a caller's transaction, as in tests), it reads in that one.
            if ($outer === 0) {
                $db->unprepared('set transaction isolation level repeatable read, read only');
            }

            $db->unprepared("declare tenant_export_rows no scroll cursor for {$sql}");

            do {
                $batch = $db->select("fetch forward {$chunk} from tenant_export_rows");

                foreach ($batch as $row) {
                    $line = $row->line."\n";
                    $this->count(strlen($line));
                    fwrite($handle, $line);
                }

                $count += count($batch);
            } while (count($batch) === $chunk);

            $db->unprepared('close tenant_export_rows');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();

            throw $e;
        } finally {
            fclose($handle);
        }

        return $count;
    }

    /**
     * Add the tenant's media under `files/`, recording each in $files.
     *
     * @param  list<array{path: string, size: int, sha256: string}>  $files
     */
    private function addMedia(string $tenantId, string $dir, ZipArchive &$zip, string $zipPath, array &$files): int
    {
        // NFR-07: the tenant files disk; on the object store every disk name
        // rows carry (`media`, `s3`) is the same bucket.
        $diskName = TenantFiles::disk();
        $disk = Storage::disk($diskName);
        $prefix = TenantFiles::PREFIX."{$tenantId}/";
        $exports = "{$prefix}exports/";
        $local = config("filesystems.disks.{$diskName}.driver") === 'local';
        $pending = [];
        $pendingBytes = 0;
        $added = 0;

        foreach ($disk->allFiles(rtrim($prefix, '/')) as $path) {
            if (! str_starts_with($path, $prefix) || str_starts_with($path, $exports)) {
                continue;
            }

            $relative = substr($path, strlen($prefix));

            if ($relative === '' || str_contains($relative, '..')) {
                continue;
            }

            $this->count((int) $disk->size($path));
            $source = $local ? $disk->path($path) : $this->copy($disk, $path, "{$dir}/media/".Str::uuid7());
            $zip->addFile($source, "files/{$relative}");
            $files[] = ['path' => "files/{$relative}", 'size' => (int) filesize($source), 'sha256' => (string) hash_file('sha256', $source)];
            $added++;

            if (! $local) {
                $pending[] = $source;
                $pendingBytes += (int) filesize($source);

                // Write what is queued into the zip, then drop the copies.
                if ($pendingBytes >= self::FLUSH_BYTES || count($pending) >= self::FLUSH_FILES) {
                    if (! $zip->close()) {
                        throw new RuntimeException('The export zip could not be written.');
                    }

                    array_map('unlink', $pending);
                    [$pending, $pendingBytes] = [[], 0];
                    $zip = $this->open($zipPath, 0);
                }
            }
        }

        return $added;
    }

    private function copy(Filesystem $disk, string $path, string $local): string
    {
        $in = $disk->readStream($path);
        $out = fopen($local, 'xb');

        if ($in === null || $out === false) {
            throw new RuntimeException("The media file [{$path}] could not be copied.");
        }

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }

        return $local;
    }

    private function open(string $path, int $flags): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($path, $flags) !== true) {
            throw new RuntimeException('The export zip could not be opened.');
        }

        return $zip;
    }

    private function count(int $bytes): void
    {
        $this->bytes += $bytes;

        if ($this->bytes > $this->maxBytes) {
            throw new ExportTooLarge($this->maxBytes);
        }
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
