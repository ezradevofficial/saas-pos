<?php

namespace App\Core\Files\Console;

use App\Core\Files\TenantFiles;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * NFR-07, TEN-01: copies the files kept on this server's disk to the
 * object store (Linode Object Storage), under the same keys, so the rows
 * that name them keep working (on the object store the `media` disk is
 * the same bucket, config/filesystems.php).
 *
 * Sources: `media_local` (storage/app/media: every tenant file) and
 * `local` (storage/app/private: proof photos and marketplace logos written
 * there before NFR-07). Only tenant keys (`tenants/{uuid}/...`) and the
 * legacy marketplace logo keys are copied; anything else (work files,
 * probes, unfinished `.part` uploads) is left alone and listed.
 *
 * - Idempotent and resumable: a key already in the bucket with the same
 *   size and SHA-256 is counted as done and not copied again, so a run
 *   that stopped can simply be started again.
 * - Every copy is verified (size and SHA-256 read back from the bucket).
 *   A copy that does not verify is removed from the bucket and reported.
 * - A key already in the bucket with other content is a conflict: never
 *   overwritten, reported, and the run fails.
 * - Local files are deleted only with --delete-local, and only once their
 *   copy is verified.
 * - --dry-run lists what would happen and writes nothing.
 *
 * Exit 1 when any file failed or conflicted.
 */
class MigrateToObjectStorage extends Command
{
    protected $signature = 'storage:migrate-to-object-storage
        {--dry-run : List what would be copied; write and delete nothing}
        {--delete-local : Delete each local file once its copy is verified}
        {--to= : The target disk (default: the tenant files disk, MEDIA_DISK)}';

    protected $description = 'Copy local tenant files to the object store, verified, resumable';

    /** Source disks and the key prefixes copied from each. */
    public const SOURCES = [
        'media_local' => [TenantFiles::PREFIX],
        'local' => [TenantFiles::PREFIX, self::LEGACY_MARKETPLACE_PREFIX],
    ];

    /** Marketplace logos before NFR-07 (no tenant in the key; rows name them). */
    public const LEGACY_MARKETPLACE_PREFIX = 'marketplace/apps/';

    /** @var array<string, int> */
    private array $counts = ['copied' => 0, 'present' => 0, 'would_copy' => 0, 'deleted_local' => 0, 'skipped' => 0, 'conflict' => 0, 'failed' => 0];

    public function handle(): int
    {
        $target = (string) ($this->option('to') ?: TenantFiles::disk());
        $dry = (bool) $this->option('dry-run');
        $deleteLocal = (bool) $this->option('delete-local');

        if (! TenantFiles::isObjectStore($target)) {
            $this->components->error("The target disk [{$target}] is not an object store (set MEDIA_DISK=s3 and the AWS_* variables, or pass --to=s3).");

            return self::FAILURE;
        }

        $this->components->info(sprintf('Copying local files to [%s]%s.', $target, $dry ? ' (dry run: nothing is written or deleted)' : ''));
        $to = Storage::disk($target);

        foreach (self::SOURCES as $source => $prefixes) {
            if (config("filesystems.disks.{$source}.driver") !== 'local') {
                continue;
            }

            $from = Storage::disk($source);

            foreach ($from->allFiles() as $key) {
                if (! $this->copyable($key, $prefixes)) {
                    $this->counts['skipped']++;
                    $this->line("  skip      {$source}:{$key}", verbosity: 'v');

                    continue;
                }

                $this->migrate($source, $from, $to, $key, $dry, $deleteLocal);
            }
        }

        $this->newLine();
        foreach ($this->counts as $label => $count) {
            $this->components->twoColumnDetail(str_replace('_', ' ', $label), (string) $count);
        }

        if ($this->counts['failed'] > 0 || $this->counts['conflict'] > 0) {
            $this->components->error('Some files were not migrated. Their local copies are kept; fix the cause and run the command again.');

            return self::FAILURE;
        }

        $this->components->info($dry ? 'Dry run done.' : 'Done.');

        return self::SUCCESS;
    }

    /** @param  list<string>  $prefixes */
    private function copyable(string $key, array $prefixes): bool
    {
        if (str_ends_with($key, '.part')) {
            return false;
        }

        foreach ($prefixes as $prefix) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            return $prefix === TenantFiles::PREFIX
                ? TenantFiles::tenantOf($key) !== null
                : ! str_contains($key, '..');
        }

        return false;
    }

    private function migrate(string $source, Filesystem $from, Filesystem $to, string $key, bool $dry, bool $deleteLocal): void
    {
        try {
            $local = $from->path($key);
            $size = (int) filesize($local);
            $sha = (string) hash_file('sha256', $local);

            if ($to->exists($key)) {
                if ($this->matches($to, $key, $size, $sha)) {
                    $this->counts['present']++;
                    $this->line("  present   {$key}", verbosity: 'v');
                    $this->deleteLocal($from, $key, $dry, $deleteLocal);
                } else {
                    $this->counts['conflict']++;
                    $this->components->warn("Conflict: {$key} is already in the bucket with other content; left as it is, local copy kept.");
                }

                return;
            }

            if ($dry) {
                $this->counts['would_copy']++;
                $this->line("  copy      {$source}:{$key} ({$size} bytes)");

                return;
            }

            $stream = fopen($local, 'rb');

            try {
                $written = $stream !== false && $to->writeStream($key, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! $written || ! $this->matches($to, $key, $size, $sha)) {
                rescue(fn () => $to->delete($key), report: false);
                $this->counts['failed']++;
                $this->components->warn("Failed: {$key} did not verify after the copy (size or SHA-256); removed from the bucket, local copy kept.");

                return;
            }

            $this->counts['copied']++;
            $this->line("  copied    {$key}", verbosity: 'v');
            $this->deleteLocal($from, $key, $dry, $deleteLocal);
        } catch (Throwable $e) {
            $this->counts['failed']++;
            $this->components->warn("Failed: {$key}: {$e->getMessage()}; local copy kept.");
        }
    }

    /** Whether the object at $key has $size bytes and SHA-256 $sha. */
    private function matches(Filesystem $to, string $key, int $size, string $sha): bool
    {
        if ((int) $to->size($key) !== $size) {
            return false;
        }

        $stream = $to->readStream($key);

        if (! is_resource($stream)) {
            return false;
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_equals($sha, hash_final($context));
        } finally {
            fclose($stream);
        }
    }

    /** Only called once the copy at $key is verified. */
    private function deleteLocal(Filesystem $from, string $key, bool $dry, bool $deleteLocal): void
    {
        if ($dry || ! $deleteLocal) {
            return;
        }

        if ($from->delete($key)) {
            $this->counts['deleted_local']++;
        }
    }
}
