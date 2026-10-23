<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * NFR-07, TEN-01: run a feature as production does, with tenant files on
 * the object store (`s3`, faked), and check where a file landed: on the
 * configured disk, under its tenant, nowhere else, and offered only by a
 * signed API route (never a presigned object-store link).
 */
trait UsesObjectStorage
{
    protected function useObjectStorage(): void
    {
        config(['filesystems.tenant_files' => 's3']);
        Storage::fake('s3');
        Storage::fake('media');
        Storage::fake('local');
    }

    protected function assertOnObjectStorage(?string $disk, ?string $path, string $tenantId, string $under = ''): void
    {
        $this->assertSame('s3', $disk, 'The row names the configured disk.');
        $this->assertNotNull($path);
        $this->assertStringStartsWith("tenants/{$tenantId}/{$under}", $path, 'Every key starts with its tenant.');
        Storage::disk('s3')->assertExists($path);
        $this->assertSame([], Storage::disk('media')->allFiles(), 'Nothing on the local media disk.');
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Nothing on the server\'s own disk.');
    }

    protected function assertSignedApiUrl(?string $url): void
    {
        $this->assertNotNull($url);
        $this->assertStringNotContainsString('X-Amz-', $url, 'Never a presigned object-store link.');
        $this->assertStringContainsString('/api/v1/', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
    }
}
