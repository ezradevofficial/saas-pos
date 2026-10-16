<?php

namespace Modules\Payroll\Support;

use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stage 7C closing review: bulk `insert … on conflict … do update` for
 * Payroll's copy of HR, so a sync writes a few statements instead of a
 * query per row and two concurrent syncs never fail on a unique key.
 * `$guard` (a raw SQL condition on `t`, the stored row, and `excluded`,
 * the incoming one) keeps a newer stored row from being overwritten; a
 * null `$update` inserts only what is missing.
 */
final class Upserts
{
    /**
     * @param  list<array<string, mixed>>  $rows  without id, tenant_id or timestamps
     * @param  list<string>  $uniqueBy  the columns of a unique index, after tenant_id
     * @param  list<string>|null  $update  the columns a conflict updates (null: do nothing)
     * @return int rows inserted or updated
     */
    public static function run(string $table, array $rows, array $uniqueBy, ?array $update, ?string $guard = null): int
    {
        if ($rows === []) {
            return 0;
        }

        $tenantId = app(TenantContext::class)->require();
        $now = CarbonImmutable::now()->toIso8601String();
        $columns = array_keys($rows[0]);
        $all = ['id', 'tenant_id', ...$columns, 'created_at', 'updated_at'];
        $written = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $values = [];
            $bindings = [];

            foreach ($chunk as $row) {
                $values[] = '('.implode(', ', array_fill(0, count($all), '?')).')';
                array_push($bindings, (string) Str::uuid7(), $tenantId, ...array_map(fn (string $c) => $row[$c] ?? null, $columns));
                array_push($bindings, $now, $now);
            }

            $sql = sprintf('insert into %s as t (%s) values %s on conflict (tenant_id, %s) ', $table, implode(', ', $all), implode(', ', $values), implode(', ', $uniqueBy));

            if ($update === null) {
                $sql .= 'do nothing';
            } else {
                $sets = array_map(fn (string $c) => "{$c} = excluded.{$c}", [...$update, 'updated_at']);
                $sql .= 'do update set '.implode(', ', $sets).($guard === null ? '' : " where {$guard}");
            }

            $written += DB::connection(TenantContext::CONNECTION)->affectingStatement($sql, $bindings);
        }

        return $written;
    }
}
