<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The scheduler's fan-out commands (APR-05, AUTO-01, AUTO-05, NOT-05,
// CUR-03) must find the tenants with work due before any tenant is set.
// They used to read those ids through the owner (BYPASSRLS) connection, so
// the scheduler host needed the owner's credentials. These functions, owned
// by the schema owner (ADR 002), answer the same questions and return only
// tenant ids, never a row. Each one pins search_path, schema-qualifies every
// name, is revoked from PUBLIC and granted to the runtime role only.
return new class extends Migration
{
    /** @var array<string, string> signature => body */
    private const FUNCTIONS = [
        // APR-05: a pending request whose reminder or escalation is due.
        'app_tenants_with_due_approval_timers(p_at timestamptz)' => <<<'SQL'
            select distinct r.tenant_id from public.approval_requests r
            where r.status = 'pending'
              and (r.next_reminder_at <= p_at or r.escalate_at <= p_at)
            order by 1
            SQL,
        // AUTO-01: live rules of a timed kind ('schedule' or 'date');
        // schedule rules only when their next run is due.
        'app_tenants_with_due_automation(p_kind text, p_at timestamptz)' => <<<'SQL'
            select distinct r.tenant_id from public.automation_rules r
            where p_kind in ('schedule', 'date')
              and r.trigger_type = p_kind
              and r.enabled and r.archived_at is null
              and (p_kind <> 'schedule' or r.next_run_at <= p_at)
            order by 1
            SQL,
        // AUTO-05: runs and webhook deliveries a dead worker left behind.
        'app_tenants_with_stuck_automation(p_stale_before timestamptz)' => <<<'SQL'
            select t.tenant_id from (
                select r.tenant_id from public.automation_runs r
                where r.outcome = 'running' and r.updated_at < p_stale_before
                union
                select d.tenant_id from public.automation_webhook_deliveries d
                where d.status in ('sending', 'pending', 'retrying') and d.updated_at < p_stale_before
            ) t
            order by 1
            SQL,
        // NOT-05: emails held for a digest.
        'app_tenants_with_pending_digests()' => <<<'SQL'
            select distinct d.tenant_id from public.notification_deliveries d
            where d.status = 'pending_digest'
            order by 1
            SQL,
        // CUR-03: every active tenant; companies with a feed are then read
        // in each tenant's context.
        'app_active_tenant_ids()' => <<<'SQL'
            select t.id from public.tenants t
            where t.status = 'active'
            order by 1
            SQL,
    ];

    public function up(): void
    {
        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        foreach (self::FUNCTIONS as $signature => $body) {
            $name = strstr($signature, '(', true);
            $types = $this->types($signature);

            DB::unprepared(<<<SQL
                create or replace function public.{$signature} returns setof uuid
                language sql stable security definer
                set search_path = pg_catalog, public
                as \$fn\$
                {$body}
                \$fn\$;

                revoke all on function public.{$name}({$types}) from public;
                SQL);

            DB::statement("grant execute on function public.{$name}({$types}) to {$runtimeRole}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::FUNCTIONS) as $signature) {
            DB::statement('drop function if exists public.'.strstr($signature, '(', true).'('.$this->types($signature).')');
        }
    }

    /** "f(p_a text, p_b timestamptz)" => "text, timestamptz" */
    private function types(string $signature): string
    {
        $args = trim(substr($signature, strpos($signature, '(') + 1, -1));

        if ($args === '') {
            return '';
        }

        return implode(', ', array_map(fn ($arg) => trim(explode(' ', trim($arg), 2)[1]), explode(',', $args)));
    }
};
