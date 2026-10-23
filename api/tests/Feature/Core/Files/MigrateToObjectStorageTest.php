<?php

namespace Tests\Feature\Core\Files;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * NFR-07, TEN-01: `storage:migrate-to-object-storage` copies local tenant
 * files to the bucket under the same keys: dry run, verified copies (size
 * and SHA-256), idempotent and resumable, conflicts never overwritten,
 * local files deleted only on request and only once verified.
 */
class MigrateToObjectStorageTest extends TestCase
{
    private const A = '0199f5a2-7c1e-7d3a-9b2f-3c4d5e6f7a8b';

    private const B = '0199f5a2-1111-7222-8333-444455556666';

    private FilesystemAdapter $media;

    private FilesystemAdapter $local;

    private FilesystemAdapter $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.tenant_files' => 's3']);
        $this->media = Storage::fake('media_local');
        $this->local = Storage::fake('local');
        $this->bucket = Storage::fake('s3');

        $this->media->put('tenants/'.self::A.'/items/i-1/photo.jpg', 'item image of A');
        $this->media->put('tenants/'.self::A.'/branding/logo.png', 'logo of A');
        $this->media->put('tenants/'.self::B.'/hr/documents/contract.pdf', 'contract of B');
        // Not copied: an unfinished export upload, a probe, a key without a tenant.
        $this->media->put('tenants/'.self::A.'/exports/x.zip.part', 'partial');
        $this->media->put('health/probe.txt', 'probe');
        $this->media->put('tenants/acme/items/x.jpg', 'no tenant id');
        // The server's own disk: proof photos and marketplace logos from before NFR-07, and work files.
        $this->local->put('tenants/'.self::B.'/ecommerce/proofs/d-1.jpg', 'proof of B');
        $this->local->put('marketplace/apps/app-1/logo.png', 'legacy logo');
        $this->local->put('tenant-exports/work/table.jsonl', 'work file');
        $this->local->put('status/status.json', '{}');
    }

    /** @return array{int, string} */
    private function migrate(array $options = []): array
    {
        $code = Artisan::call('storage:migrate-to-object-storage', $options);

        return [$code, Artisan::output()];
    }

    private const COPIED = [
        'tenants/'.self::A.'/items/i-1/photo.jpg',
        'tenants/'.self::A.'/branding/logo.png',
        'tenants/'.self::B.'/hr/documents/contract.pdf',
        'tenants/'.self::B.'/ecommerce/proofs/d-1.jpg',
        'marketplace/apps/app-1/logo.png',
    ];

    public function test_a_dry_run_lists_the_copies_and_writes_and_deletes_nothing(): void
    {
        [$code, $output] = $this->migrate(['--dry-run' => true, '--delete-local' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('dry run', $output);
        foreach (self::COPIED as $key) {
            $this->assertStringContainsString($key, $output);
        }
        $this->assertMatchesRegularExpression('/would copy\s*\.+\s*5/', $output);
        $this->assertSame([], $this->bucket->allFiles());
        $this->assertCount(6, $this->media->allFiles());
        $this->assertCount(4, $this->local->allFiles());
    }

    public function test_tenant_files_are_copied_under_the_same_keys_and_verified_and_local_files_kept(): void
    {
        [$code, $output] = $this->migrate();

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/copied\s*\.+\s*5/', $output);
        $this->assertEqualsCanonicalizing(self::COPIED, $this->bucket->allFiles());
        $this->assertSame('item image of A', $this->bucket->get('tenants/'.self::A.'/items/i-1/photo.jpg'));
        $this->assertSame('proof of B', $this->bucket->get('tenants/'.self::B.'/ecommerce/proofs/d-1.jpg'));
        // Never copied: work files, probes, partial uploads, keys without a tenant.
        $this->bucket->assertMissing('tenants/'.self::A.'/exports/x.zip.part');
        $this->bucket->assertMissing('health/probe.txt');
        $this->bucket->assertMissing('tenant-exports/work/table.jsonl');
        // Without --delete-local nothing local is removed.
        $this->assertCount(6, $this->media->allFiles());
        $this->assertCount(4, $this->local->allFiles());
    }

    public function test_running_again_copies_nothing_and_a_stopped_run_resumes(): void
    {
        // A run that stopped after one file: that one is in the bucket already.
        $this->bucket->put('tenants/'.self::A.'/items/i-1/photo.jpg', 'item image of A');

        [$code, $output] = $this->migrate();
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/copied\s*\.+\s*4/', $output);
        $this->assertMatchesRegularExpression('/present\s*\.+\s*1/', $output);

        [$code, $output] = $this->migrate();
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/copied\s*\.+\s*0/', $output);
        $this->assertMatchesRegularExpression('/present\s*\.+\s*5/', $output);
        $this->assertCount(5, $this->bucket->allFiles());
    }

    public function test_delete_local_removes_only_verified_copies(): void
    {
        [$code, $output] = $this->migrate(['--delete-local' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/deleted local\s*\.+\s*5/', $output);
        // What was not copied stays.
        $this->assertEqualsCanonicalizing([
            'tenants/'.self::A.'/exports/x.zip.part', 'health/probe.txt', 'tenants/acme/items/x.jpg',
        ], $this->media->allFiles());
        $this->assertEqualsCanonicalizing(['tenant-exports/work/table.jsonl', 'status/status.json'], $this->local->allFiles());
        $this->assertCount(5, $this->bucket->allFiles());
    }

    public function test_a_key_already_in_the_bucket_with_other_content_is_never_overwritten(): void
    {
        $this->bucket->put('tenants/'.self::A.'/branding/logo.png', 'something else');

        [$code, $output] = $this->migrate(['--delete-local' => true]);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('Conflict: tenants/'.self::A.'/branding/logo.png', $output);
        $this->assertSame('something else', $this->bucket->get('tenants/'.self::A.'/branding/logo.png'));
        $this->media->assertExists('tenants/'.self::A.'/branding/logo.png');
        // The others were still copied and verified.
        $this->assertMatchesRegularExpression('/copied\s*\.+\s*4/', $output);
    }

    public function test_a_copy_that_does_not_verify_is_removed_from_the_bucket_and_the_local_file_kept(): void
    {
        $fake = $this->bucket;
        $corrupting = new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function writeStream($path, $resource, array $options = [])
            {
                return $this->put($path, 'corrupted in transit');
            }
        };
        Storage::set('s3', $corrupting);

        [$code, $output] = $this->migrate(['--delete-local' => true]);

        $this->assertSame(1, $code, $output);
        $this->assertMatchesRegularExpression('/failed\s*\.+\s*5/', $output);
        $this->assertStringContainsString('did not verify', $output);
        $this->assertSame([], $fake->allFiles());
        $this->assertCount(6, $this->media->allFiles());
        $this->assertCount(4, $this->local->allFiles());
    }

    public function test_the_target_must_be_an_object_store(): void
    {
        config(['filesystems.tenant_files' => 'media']);

        [$code, $output] = $this->migrate();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('not an object store', $output);
        $this->assertSame([], $this->bucket->allFiles());
    }
}
