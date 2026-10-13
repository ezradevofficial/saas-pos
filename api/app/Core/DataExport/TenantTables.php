<?php

namespace App\Core\DataExport;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * EXP-02, TEN-01: the tables a full tenant export reads, discovered from
 * the schema at run time, so a new table is exported without editing any
 * list.
 *
 * - Every `public` table with a `tenant_id` column under forced row-level
 *   security, plus `tenants` itself (keyed on `id`, ADR 002).
 * - A table with `tenant_id` but no forced row-level security is one of
 *   the deliberate global tables (bearer tokens, sign-in codes; ADR 002).
 *   It holds credentials only and is left out (omitted()).
 * - Columns in table order, without the denied ones and without any
 *   secret-like column left unclassified (fail closed; ExportColumns).
 * - A column may be exported through a transform (ExportColumns::
 *   TRANSFORMS), e.g. automation actions without their webhook URLs.
 */
class TenantTables
{
    /**
     * Every exported table; `key` is the column naming the tenant.
     *
     * @return list<array{name: string, key: string, columns: list<string>, omitted: list<string>, primary: list<string>}>
     */
    public function all(): array
    {
        $rows = DB::connection(TenantContext::CONNECTION)->select(<<<'SQL'
            select c.relname as name,
                   (c.relname = 'tenants') as is_tenants
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind = 'r'
              and (
                c.relname = 'tenants'
                or (c.relrowsecurity and c.relforcerowsecurity and exists (
                    select 1 from pg_attribute a
                    where a.attrelid = c.oid and a.attname = 'tenant_id' and not a.attisdropped))
              )
            order by c.relname
            SQL);

        $tables = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;
            $all = $this->columns($name);
            $omitted = array_values(array_filter($all, fn (string $column) => ! $this->exported($name, $column)));
            // Rows come out in primary-key order when there is one (stable files).
            $primary = $this->primaryKey($name);

            $tables[] = [
                'name' => $name,
                'key' => $row->is_tenants ? 'id' : 'tenant_id',
                'columns' => array_values(array_diff($all, $omitted)),
                'omitted' => $omitted,
                'primary' => $primary,
            ];
        }

        return $tables;
    }

    /** @return list<string> tables with a tenant_id that are global (no forced row-level security), never exported */
    public function omitted(): array
    {
        return array_map(fn ($row) => (string) $row->name, DB::connection(TenantContext::CONNECTION)->select(<<<'SQL'
            select c.relname as name from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind = 'r'
              and not (c.relrowsecurity and c.relforcerowsecurity)
              and exists (select 1 from pg_attribute a where a.attrelid = c.oid and a.attname = 'tenant_id' and not a.attisdropped)
            order by c.relname
            SQL));
    }

    public function exported(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";

        if (isset(ExportColumns::DENIED[$key])) {
            return false;
        }

        // Fail closed: a secret-like column nobody reviewed stays out.
        return ! ExportColumns::isSecretLike($column) || isset(ExportColumns::ALLOWED[$key]);
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        return array_map(fn ($row) => (string) $row->name, DB::connection(TenantContext::CONNECTION)->select(<<<'SQL'
            select a.attname as name from pg_attribute a
            where a.attrelid = ('public.' || quote_ident(?))::regclass and a.attnum > 0 and not a.attisdropped
            order by a.attnum
            SQL, [$table]));
    }

    /** @return list<string> */
    private function primaryKey(string $table): array
    {
        return array_map(fn ($row) => (string) $row->name, DB::connection(TenantContext::CONNECTION)->select(<<<'SQL'
            select a.attname as name
            from pg_index i
            join lateral unnest(i.indkey) with ordinality as k(attnum, ord) on true
            join pg_attribute a on a.attrelid = i.indrelid and a.attnum = k.attnum
            where i.indrelid = ('public.' || quote_ident(?))::regclass and i.indisprimary
            order by k.ord
            SQL, [$table]));
    }
}
