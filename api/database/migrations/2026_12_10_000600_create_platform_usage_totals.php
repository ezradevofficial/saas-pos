<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADM-06 (docs/modules/platform-admin.md, ADR 002): usage totals across
// tenants for the platform's reports. A narrow security-definer aggregate:
// per unit, how many tenants were metered and the sum of their counts on
// the latest metered day on or before p_day. Totals only: no tenant id,
// no row. Granted to the runtime role, which the console's report uses.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function public.app_platform_usage_totals(p_day date)
            returns table (unit text, tenants bigint, total bigint)
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select m.unit::text, count(distinct m.tenant_id), coalesce(sum(m.count), 0)::bigint
                from public.usage_meters m
                where m.day = (select max(x.day) from public.usage_meters x where x.day <= p_day)
                group by m.unit
                order by m.unit
            $$;
            revoke all on function public.app_platform_usage_totals(date) from public;
            SQL);

        $runtime = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("grant execute on function public.app_platform_usage_totals(date) to {$runtime}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.app_platform_usage_totals(date)');
    }
};
