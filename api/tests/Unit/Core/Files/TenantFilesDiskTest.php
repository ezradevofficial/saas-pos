<?php

namespace Tests\Unit\Core\Files;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NFR-07: which disk tenant files go to, from the environment alone. A
 * configured bucket (AWS_BUCKET) is enough to switch the object store on,
 * as on staging; MEDIA_DISK overrides it either way; nothing set is the
 * local disk (development).
 */
class TenantFilesDiskTest extends TestCase
{
    private const NAMES = ['MEDIA_DISK', 'AWS_BUCKET'];

    /** @var array<string, array{mixed, mixed, string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::NAMES as $name) {
            $this->saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$env, $server, $process]) {
            $this->set($name, null);
            $env === null ? null : $_ENV[$name] = $env;
            $server === null ? null : $_SERVER[$name] = $server;
            $process === false ? null : putenv("{$name}={$process}");
        }

        parent::tearDown();
    }

    private function set(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }

    /** @return array<string, array{?string, ?string, string, string}> */
    public static function environments(): array
    {
        return [
            'nothing set: local' => [null, null, 'media', 'local'],
            'a bucket: the object store' => [null, 'files-bucket', 's3', 's3'],
            'MEDIA_DISK=media keeps the local disk' => ['media', 'files-bucket', 'media', 'local'],
            'MEDIA_DISK=s3' => ['s3', 'files-bucket', 's3', 's3'],
        ];
    }

    #[DataProvider('environments')]
    public function test_the_tenant_files_disk_follows_the_environment(?string $mediaDisk, ?string $bucket, string $disk, string $mediaDriver): void
    {
        $this->set('MEDIA_DISK', $mediaDisk);
        $this->set('AWS_BUCKET', $bucket);

        $config = require config_path('filesystems.php');

        $this->assertSame($disk, $config['tenant_files']);
        // Rows written before the switch name `media`: on the object store it is the bucket.
        $this->assertSame($mediaDriver, $config['disks']['media']['driver']);
        $this->assertSame('local', $config['disks']['media_local']['driver']);
        $this->assertSame('private', $config['disks']['s3']['visibility']);
        $this->assertTrue($config['disks']['s3']['throw']);
    }
}
