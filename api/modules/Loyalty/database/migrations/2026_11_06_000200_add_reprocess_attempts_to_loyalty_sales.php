<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// LOY-03: `loyalty:reprocess` retries a sale that failed with a cap and a
// backoff (`attempts`, `next_attempt_at`); past the cap the sale is
// `failed_permanently` and shows in the flagged-sales review. The
// scheduler finds the tenants with sales to retry through a
// security-definer function that returns tenant ids only (ADR 002).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        DB::statement('alter table loyalty_sales alter column status type varchar(20)');
        DB::statement('alter table loyalty_sales drop constraint loyalty_sales_status_check');
        DB::statement("alter table loyalty_sales add constraint loyalty_sales_status_check check (status in ('pending', 'processed', 'failed', 'failed_permanently'))");
        DB::statement('alter table loyalty_sales add column attempts smallint not null default 0, add column next_attempt_at timestamptz');
        DB::statement('alter table loyalty_sales add constraint loyalty_sales_attempts_check check (attempts >= 0)');
        DB::statement("create index loyalty_sales_to_reprocess_index on loyalty_sales (next_attempt_at) where status in ('pending', 'failed')");

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::unprepared(<<<'SQL'
            create or replace function public.app_tenants_with_loyalty_sales_to_reprocess(p_at timestamptz, p_pending_before timestamptz) returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $fn$
                select distinct s.tenant_id from public.loyalty_sales s
                where (s.status = 'failed' and (s.next_attempt_at is null or s.next_attempt_at <= p_at))
                   or (s.status = 'pending' and s.updated_at < p_pending_before)
                order by 1
            $fn$;

            revoke all on function public.app_tenants_with_loyalty_sales_to_reprocess(timestamptz, timestamptz) from public;
            SQL);
        DB::statement("grant execute on function public.app_tenants_with_loyalty_sales_to_reprocess(timestamptz, timestamptz) to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::unprepared('drop function if exists public.app_tenants_with_loyalty_sales_to_reprocess(timestamptz, timestamptz)');
        DB::statement('drop index if exists loyalty_sales_to_reprocess_index');
        DB::statement('alter table loyalty_sales drop constraint if exists loyalty_sales_attempts_check');
        DB::statement('alter table loyalty_sales drop column attempts, drop column next_attempt_at');
        DB::statement("update loyalty_sales set status = 'failed' where status = 'failed_permanently'");
        DB::statement('alter table loyalty_sales drop constraint loyalty_sales_status_check');
        DB::statement("alter table loyalty_sales add constraint loyalty_sales_status_check check (status in ('pending', 'processed', 'failed'))");
        DB::statement('alter table loyalty_sales alter column status type varchar(10)');
    }
};
