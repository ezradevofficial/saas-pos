<?php

namespace Tests\Unit\Core\Files;

use App\Core\Files\TenantFiles;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * TEN-01, NFR-07: every object key starts with its tenant
 * (`tenants/{uuid}/`), and only TenantFiles builds keys.
 *
 * The guard reads the application's source (app/, modules/; route files,
 * whose `tenants/...` are URL patterns, aside): outside
 * TenantFiles no code may spell a key prefix (`tenants/` at the start of
 * a string) or write to a disk with a key it did not get from TenantFiles.
 * Each file that writes to a disk must build its keys with
 * TenantFiles::key or TenantFiles::platformKey, or be listed below with
 * the reason its key is already a TenantFiles key.
 */
class TenantKeysTest extends TestCase
{
    /** Files that write keys they were handed by a TenantFiles caller, or a configured platform path. */
    private const WRITERS_WITHOUT_BUILDING = [
        // Writes an import's files under ImportFiles::directory(), which is TenantFiles::key.
        'app/Core/Imports/ImportFiles.php' => 'directory() is TenantFiles::key',
        // `health:status` writes the public status snapshot at its configured path (no tenant data, local disk).
        'app/Core/Observability/Console/StatusSnapshot.php' => 'observability.status.path, a platform file',
        // Copies existing keys unchanged (tenant keys only, checked with TenantFiles::tenantOf).
        'app/Core/Files/Console/MigrateToObjectStorage.php' => 'copies keys as they are',
    ];

    /** A disk write (not a call on $this). */
    private const WRITE_CALLS = '/(?<!\$this)->(put|putFile|putFileAs|writeStream|prepend|append|copy|move)\s*\(/';

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /** @return list<string> PHP files of app/ and modules/, without tests */
    private static function sources(): array
    {
        $files = [];

        foreach (['app', 'modules'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root().'/'.$dir));

            foreach ($iterator as $file) {
                $path = substr($file->getPathname(), strlen(self::root()) + 1);

                if ($file->isFile() && str_ends_with($path, '.php') && ! preg_match('#/(tests|database|routes)/#', $path)) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        return $files;
    }

    public function test_no_code_outside_tenant_files_spells_a_key_prefix(): void
    {
        $offenders = [];

        foreach (self::sources() as $path) {
            if ($path === 'app/Core/Files/TenantFiles.php') {
                continue;
            }

            $code = (string) file_get_contents(self::root().'/'.$path);

            // A string literal starting with `tenants/` or `tenants/%s`: a key built by hand.
            if (preg_match('/[\'"]tenants\/(?:%s|\{|\'|"|\.)/', $code) === 1 || preg_match('/sprintf\(\s*[\'"]tenants\//', $code) === 1) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'Build object keys with TenantFiles::key(), never by hand.');
    }

    public function test_every_file_that_writes_to_a_disk_builds_its_keys_with_tenant_files(): void
    {
        $writers = [];

        foreach (self::sources() as $path) {
            $code = (string) file_get_contents(self::root().'/'.$path);

            if (! str_contains($code, 'Storage::disk') && ! str_contains($code, 'Filesystem')) {
                continue;
            }

            if (preg_match(self::WRITE_CALLS, $code) !== 1 || ! preg_match('/Storage::disk|\$disk|\$to->|\$storage->/', $code)) {
                continue;
            }

            $writers[] = $path;
            $builds = str_contains($code, 'TenantFiles::key(') || str_contains($code, 'TenantFiles::platformKey(');

            $this->assertTrue(
                $builds || array_key_exists($path, self::WRITERS_WITHOUT_BUILDING),
                "{$path} writes to a disk: build its keys with TenantFiles::key() (or list it here with the reason)."
            );
        }

        // The guard sees the writers it is meant to (it would pass vacuously otherwise).
        foreach (['app/Core/MasterData/Items/ItemImages.php', 'app/Core/Branding/BrandAssets.php', 'app/Core/DataExport/TenantExports.php', 'modules/HR/Records/Documents.php', 'modules/Ecommerce/Http/Controllers/RiderAppController.php'] as $known) {
            $this->assertContains($known, $writers);
        }
    }

    public function test_keys_start_with_the_tenant(): void
    {
        $tenant = '0199f5a2-7c1e-7d3a-9b2f-3c4d5e6f7a8b';

        $this->assertSame("tenants/{$tenant}/items/i-1/a.jpg", TenantFiles::key($tenant, 'items', 'i-1', 'a.jpg'));
        $this->assertSame("tenants/{$tenant}/imports/x/source.csv", TenantFiles::key(strtoupper($tenant), 'imports/x', 'source.csv'));
        $this->assertSame($tenant, TenantFiles::tenantOf(TenantFiles::key($tenant, 'branding', 'logo.png')));
        $this->assertSame('platform/health/p.txt', TenantFiles::platformKey('health', 'p.txt'));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function badKeys(): array
    {
        return [
            'no tenant' => ['', ['items', 'a.jpg']],
            'not a uuid' => ['acme', ['items', 'a.jpg']],
            'climbs' => ['0199f5a2-7c1e-7d3a-9b2f-3c4d5e6f7a8b', ['..', 'other', 'a.jpg']],
            'climbs inside a segment' => ['0199f5a2-7c1e-7d3a-9b2f-3c4d5e6f7a8b', ['items/../../x']],
            'empty segment' => ['0199f5a2-7c1e-7d3a-9b2f-3c4d5e6f7a8b', ['items', '']],
            'absolute' => ['0199f5a2-7c1e-7d3a-9b2f-3c4d5e6f7a8b', ['/etc/passwd']],
            'no name' => ['0199f5a2-7c1e-7d3a-9b2f-3c4d5e6f7a8b', []],
        ];
    }

    /** @param  list<string>  $segments */
    #[DataProvider('badKeys')]
    public function test_a_key_without_a_tenant_or_that_climbs_is_refused(string $tenant, array $segments): void
    {
        $this->expectException(InvalidArgumentException::class);

        TenantFiles::key($tenant, ...$segments);
    }

    public function test_tenant_of_reads_only_well_formed_tenant_keys(): void
    {
        $this->assertNull(TenantFiles::tenantOf('marketplace/apps/x/logo.png'));
        $this->assertNull(TenantFiles::tenantOf('tenants/acme/items/a.jpg'));
        $this->assertNull(TenantFiles::tenantOf('tenants/0199f5a2-7c1e-7d3a-9b2f-3c4d5e6f7a8b/../x'));
        $this->assertNull(TenantFiles::tenantOf('tenants/0199f5a2-7c1e-7d3a-9b2f-3c4d5e6f7a8b/'));
    }
}
