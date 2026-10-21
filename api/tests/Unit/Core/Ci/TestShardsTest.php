<?php

namespace Tests\Unit\Core\Ci;

use PHPUnit\Framework\TestCase;

/**
 * CI runs the module tests in two shards (phpunit.xml ShardModulesA and
 * ShardModulesB). Every module's tests folder is in exactly one of them,
 * so a new module's tests can never be left out of CI.
 */
class TestShardsTest extends TestCase
{
    public function test_every_module_is_in_exactly_one_module_shard(): void
    {
        $root = dirname(__DIR__, 4);
        $xml = simplexml_load_file($root.'/phpunit.xml');
        $listed = [];

        foreach (['ShardModulesA', 'ShardModulesB'] as $shard) {
            foreach ($xml->xpath("//testsuite[@name='{$shard}']/directory") as $directory) {
                $listed[] = (string) $directory;
            }
        }

        $modules = array_map(fn (string $path) => 'modules/'.basename(dirname($path)).'/tests', glob($root.'/modules/*/tests', GLOB_ONLYDIR));
        sort($modules);
        $unique = array_unique($listed);
        sort($unique);

        $this->assertSame(count($listed), count($unique), 'a module is in both shards');
        $this->assertSame($modules, $unique);
    }
}
