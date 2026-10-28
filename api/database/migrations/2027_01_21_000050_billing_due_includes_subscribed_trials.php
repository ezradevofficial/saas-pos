<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// SUB-03, SUB-05: a trial subscribed to early ("Subscribe now") has its first
// period set, starting at the trial end. The billing clock must still reach it
// around the trial end (its reminders, and turning it active once the trial
// ends), so a trial is due from 7 days before its end whether or not its first
// period is set. `create or replace` keeps the function's grants (ADR 002).
return new class extends Migration
{
    public function up(): void
    {
        $this->define("(s.status = 'trial' and s.trial_ends_at <= p_at + interval '7 days')");
    }

    public function down(): void
    {
        $this->define("(s.status = 'trial' and s.current_period_start is null and s.trial_ends_at <= p_at + interval '7 days')");
    }

    private function define(string $trial): void
    {
        DB::unprepared(<<<SQL
            create or replace function public.app_tenants_with_billing_due(p_at timestamptz) returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as \$fn\$
            select s.tenant_id from public.subscriptions s
            join public.tenants t on t.id = s.tenant_id
            where t.status = 'active' and not t.is_operator
              and s.status in ('trial', 'active', 'past_due', 'read_only')
              and (
                {$trial}
                or (s.current_period_end is not null and s.current_period_end <= (p_at + interval '7 days')::date)
                or s.status in ('past_due', 'read_only')
                or exists (select 1 from public.billing_invoices i
                           where i.tenant_id = s.tenant_id and i.status = 'open' and i.due_at <= p_at)
              )
            order by 1
            \$fn\$;
            SQL);
    }
};
