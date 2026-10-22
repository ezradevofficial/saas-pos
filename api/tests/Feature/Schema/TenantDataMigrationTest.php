<?php

namespace Tests\Feature\Schema;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * TEN-01: a migration that moves tenant data enters each tenant on the
 * tenant connection. Run inside the migration's transaction on the owner
 * connection, the tenant connection waits on the owner's locks (an altered
 * table) while the owner sits idle in its transaction, and the database's
 * 60-second idle-in-transaction timeout ends the migration wherever tenants
 * already exist (found by the Phase 8 walkthrough on a copy of the dev
 * database). Such migrations opt out of the wrapping transaction.
 *
 * Phase 8 review L11: a migration reaches the tenant connection by name
 * (`TenantContext::CONNECTION`, `DB::connection('pgsql')`) or by entering a
 * tenant (`TenantContext::run`, `->run(` or `->set(` on a TenantContext).
 * Inside a tenant, `DB::table` still means the migration's own (owner)
 * connection, which bypasses row-level security and never gets the tenant
 * setting, unless the migration made the tenant connection the default.
 */
class TenantDataMigrationTest extends TestCase
{
    /** Whether $source reaches the tenant connection, so it must run outside the migration transaction. */
    public static function usesTenantConnection(string $source): bool
    {
        return str_contains($source, 'TenantContext::CONNECTION')
            || preg_match('/DB::connection\(\s*([\'"])pgsql\1\s*\)/', $source) === 1
            || self::entersTenant($source);
    }

    /** Whether $source enters a tenant context. */
    public static function entersTenant(string $source): bool
    {
        return self::tenantCalls($source, ['run', 'set']) !== [];
    }

    /**
     * Offsets just after `run(` / `set(` calls on a TenantContext: `TenantContext::run(`,
     * `app(TenantContext::class)->run(`, or `$var->run(` where $var holds a TenantContext.
     *
     * @param  list<string>  $methods
     * @return list<array{method: string, offset: int}>
     */
    private static function tenantCalls(string $source, array $methods): array
    {
        $names = ['app\(\s*(?:\\\\?[\w\\\\]*\\\\)?TenantContext::class\s*\)\s*->', '(?:\\\\?[\w\\\\]*\\\\)?TenantContext::'];

        // Variables and properties that hold a TenantContext: assigned from app(), or typed.
        preg_match_all('/\$(\w+)\s*=\s*app\(\s*(?:\\\\?[\w\\\\]*\\\\)?TenantContext::class\s*\)/', $source, $assigned);
        preg_match_all('/TenantContext\s+\$(\w+)/', $source, $typed);

        foreach (array_unique([...$assigned[1], ...$typed[1]]) as $variable) {
            $names[] = '\$(?:this->)?'.preg_quote($variable, '/').'\s*->';
        }

        $calls = [];
        $pattern = '/(?:'.implode('|', $names).')\s*('.implode('|', $methods).')\s*\(/';

        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $calls[] = ['method' => $match[1][0], 'offset' => $match[0][1] + strlen($match[0][0])];
            }
        }

        return $calls;
    }

    /** Whether $source uses `DB::table` (the migration's owner connection) while a tenant context is set. */
    public static function usesOwnerTableInTenant(string $source): bool
    {
        if (preg_match('/DB::setDefaultConnection\(\s*(?:TenantContext::CONNECTION|([\'"])pgsql\1)\s*\)/', $source) === 1) {
            return false;
        }

        foreach (self::tenantCalls($source, ['run', 'set']) as $call) {
            // Inside run(...)'s arguments (the callback), or anywhere after set(...).
            $scope = $call['method'] === 'run' ? self::arguments($source, $call['offset']) : substr($source, $call['offset']);

            if (preg_match('/DB::table\s*\(/', $scope) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The text from $offset (just after an opening parenthesis) to its closing one. */
    private static function arguments(string $source, int $offset): string
    {
        $depth = 1;
        $length = strlen($source);

        for ($i = $offset; $i < $length; $i++) {
            $depth += match ($source[$i]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return substr($source, $offset, $i - $offset);
            }
        }

        return substr($source, $offset);
    }

    /** @return iterable<string, string> file name => source */
    private function migrations(): iterable
    {
        $api = dirname(__DIR__, 3);
        $finder = Finder::create()->files()->name('*.php')
            ->in([$api.'/database/migrations', ...glob($api.'/modules/*/database/migrations')]);

        foreach ($finder as $file) {
            yield $file->getFilename() => $file->getContents();
        }
    }

    public function test_migrations_using_the_tenant_connection_run_outside_the_migration_transaction(): void
    {
        $offenders = [];

        foreach ($this->migrations() as $name => $source) {
            if (self::usesTenantConnection($source) && ! preg_match('/public\s+\$withinTransaction\s*=\s*false\s*;/', $source)) {
                $offenders[] = $name;
            }
        }

        $this->assertSame([], $offenders, 'These migrations use the tenant connection inside the migration transaction.');
    }

    public function test_migrations_never_use_the_owner_connection_inside_a_tenant(): void
    {
        $offenders = [];

        foreach ($this->migrations() as $name => $source) {
            if (self::usesOwnerTableInTenant($source)) {
                $offenders[] = $name;
            }
        }

        $this->assertSame([], $offenders, 'These migrations use DB::table (the owner connection) inside a tenant: use DB::connection(TenantContext::CONNECTION).');
    }

    /** Phase 8 review L11: each way of reaching the tenant connection is caught. */
    public function test_the_checks_catch_every_way_into_a_tenant(): void
    {
        foreach ([
            '$rows = DB::connection(TenantContext::CONNECTION)->table("items")->get();',
            "DB::connection('pgsql')->table('items')->get();",
            'DB::connection("pgsql")->statement("select 1");',
            'TenantContext::run($id, fn () => null);',
            'app(TenantContext::class)->run($id, fn () => Item::query()->count());',
            'app(\\App\\Core\\Tenancy\\TenantContext::class)->run($id, fn () => 1);',
            '$tenants = app(TenantContext::class); foreach ($ids as $id) { $tenants->run($id, fn () => 1); }',
            'function up(TenantContext $context) { $context->run($id, fn () => 1); }',
            '$context = app(TenantContext::class); $context->set($id);',
        ] as $source) {
            $this->assertTrue(self::usesTenantConnection($source), $source);
        }

        foreach ([
            "DB::connection('pgsql_owner')->table('items')->get();",
            "DB::table('tenants')->pluck('id');",
            '$runner->run($id);',
        ] as $source) {
            $this->assertFalse(self::usesTenantConnection($source), $source);
        }

        $this->assertTrue(self::usesOwnerTableInTenant('app(TenantContext::class)->run($id, function () { DB::table("items")->update(["x" => 1]); });'));
        $this->assertTrue(self::usesOwnerTableInTenant('$t = app(TenantContext::class); $t->set($id); DB::table("items")->delete();'));
        $this->assertFalse(self::usesOwnerTableInTenant('$ids = DB::table("tenants")->pluck("id"); app(TenantContext::class)->run($id, fn () => DB::connection(TenantContext::CONNECTION)->table("items")->count());'));
        $this->assertFalse(self::usesOwnerTableInTenant('DB::setDefaultConnection(TenantContext::CONNECTION); app(TenantContext::class)->run($id, fn () => DB::table("items")->count());'));
    }
}
