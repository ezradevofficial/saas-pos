<?php

namespace App\Core\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Row-level security helpers for migrations (TEN-01). Statements run on the
 * migration connection (the schema owner).
 */
class Rls
{
    public const TENANT_EXPRESSION = "NULLIF(current_setting('app.tenant_id', true), '')::uuid";

    public const POLICY = 'tenant_isolation';

    public static function enable(string $table): void
    {
        static::enableOnKey($table, 'tenant_id');
    }

    public static function enableOnKey(string $table, string $column = 'id'): void
    {
        $condition = sprintf('%s = %s', static::quote($column), self::TENANT_EXPRESSION);
        $quoted = static::quote($table);

        DB::statement("alter table {$quoted} enable row level security");
        DB::statement("alter table {$quoted} force row level security");
        DB::statement(sprintf(
            'create policy %s on %s using (%s) with check (%s)',
            self::POLICY, $quoted, $condition, $condition,
        ));
    }

    private static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
