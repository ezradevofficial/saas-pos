<?php

namespace Tests\Feature\Schema;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-05: every foreign key in a tenant table has an index that serves it,
 * so joins and lookups by parent stay index scans at 5,000 tenants.
 *
 * An index serves a foreign key when its leading columns are the key's
 * columns, optionally after the scope columns every query already filters
 * on (tenant_id under row-level security, then company_id).
 *
 * Exempt by rule: references to the ISO currency catalogue (a few hundred
 * rows, never deleted) and "who did it" columns (`*_by` → users; users are
 * never deleted, and these are read with their row, not searched by).
 *
 * The allow-list (ForeignKeyIndexAllowList) is the reviewed backlog of
 * keys on small or rarely searched tables. A new key without an index
 * fails here; so does an allow-listed key that has since been indexed or
 * dropped, so the list only shrinks. See docs/scaling.md.
 */
class ForeignKeyIndexTest extends TestCase
{
    use RefreshTenantDatabase;

    private const SCOPE_COLUMNS = ['tenant_id', 'company_id'];

    /** @return list<string> "table.col1,col2" for every unserved key in a tenant table */
    public static function unindexedForeignKeys(): array
    {
        $keys = DB::select(<<<'SQL'
            select c.conrelid::regclass::text as tbl,
                   c.confrelid::regclass::text as ref,
                   array_to_json(array(select a.attname from unnest(c.conkey) with ordinality k(attnum, ord)
                       join pg_attribute a on a.attrelid = c.conrelid and a.attnum = k.attnum order by k.ord)) as cols
            from pg_constraint c
            join pg_namespace n on n.oid = c.connamespace
            where c.contype = 'f' and n.nspname = 'public'
              and exists (select 1 from pg_attribute t where t.attrelid = c.conrelid and t.attname = 'tenant_id' and not t.attisdropped)
            SQL);

        $indexes = [];
        foreach (DB::select(<<<'SQL'
            select i.indrelid::regclass::text as tbl,
                   array_to_json(array(select a.attname from unnest(i.indkey::int2[]) with ordinality k(attnum, ord)
                       join pg_attribute a on a.attrelid = i.indrelid and a.attnum = k.attnum order by k.ord)) as cols
            from pg_index i
            join pg_class r on r.oid = i.indrelid
            join pg_namespace n on n.oid = r.relnamespace
            where n.nspname = 'public'
            SQL) as $index) {
            $indexes[$index->tbl][] = json_decode($index->cols, true);
        }

        $missing = [];
        foreach ($keys as $key) {
            $cols = json_decode($key->cols, true);

            if ($key->ref === 'currencies' || (count(array_diff($cols, ['tenant_id'])) === 1 && str_ends_with((string) end($cols), '_by') && $key->ref === 'users')) {
                continue;
            }

            if (! self::served($cols, $indexes[$key->tbl] ?? [])) {
                $missing[] = $key->tbl.'.'.implode(',', $cols);
            }
        }

        $missing = array_values(array_unique($missing));
        sort($missing);

        return $missing;
    }

    /** @param list<list<string>> $indexes */
    private static function served(array $cols, array $indexes): bool
    {
        // The key's own columns, without the scope columns (unless it is only scope columns).
        $core = array_values(array_diff($cols, self::SCOPE_COLUMNS)) ?: $cols;
        sort($core);

        foreach ($indexes as $index) {
            $direct = array_slice($index, 0, count($cols));
            sort($direct);
            $sorted = $cols;
            sort($sorted);

            while ($index !== [] && in_array($index[0], self::SCOPE_COLUMNS, true) && ! in_array($index[0], $core, true)) {
                array_shift($index);
            }
            $lead = array_slice($index, 0, count($core));
            sort($lead);

            if ($direct === $sorted || $lead === $core) {
                return true;
            }
        }

        return false;
    }

    public function test_every_tenant_foreign_key_has_an_index_or_is_allow_listed(): void
    {
        $unexpected = array_values(array_diff(self::unindexedForeignKeys(), ForeignKeyIndexAllowList::KEYS));

        $this->assertSame([], $unexpected, 'Foreign keys without an index (add one leading with tenant_id, see docs/scaling.md): '.implode(', ', $unexpected));
    }

    public function test_the_allow_list_has_no_entry_that_is_now_indexed_or_gone(): void
    {
        $stale = array_values(array_diff(ForeignKeyIndexAllowList::KEYS, self::unindexedForeignKeys()));

        $this->assertSame([], $stale, 'Remove these from ForeignKeyIndexAllowList: '.implode(', ', $stale));
    }
}
