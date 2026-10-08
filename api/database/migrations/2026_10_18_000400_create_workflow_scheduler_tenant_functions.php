<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// WF-09 and M4 (WF-10, WF-11): the tenants `workflow:process-stage-timers`
// and `credit-limits:reconcile` have work for, found before any tenant is
// set, the same way as 2026_10_18_000100 (ADR 002): owner-owned security
// definer functions that return only tenant ids, search_path pinned, every
// name schema-qualified, revoked from PUBLIC and granted to the runtime
// role only.
return new class extends Migration
{
    /** @var array<string, string> signature => body */
    private const FUNCTIONS = [
        // WF-09: an active stage position whose next reminder, overdue
        // notice or escalation is due.
        'app_tenants_with_due_stage_timers(p_at timestamptz)' => <<<'SQL'
            select distinct t.tenant_id from public.document_workflow_tokens t
            where t.status = 'active' and t.next_timer_at <= p_at
            order by 1
            SQL,
        // M4: a credit limit change still pending whose flow completed or
        // was cancelled before p_before.
        'app_tenants_with_unsettled_credit_changes(p_before timestamptz)' => <<<'SQL'
            select distinct c.tenant_id from public.credit_limit_changes c
            join public.document_workflows w
              on w.tenant_id = c.tenant_id and w.document_id = c.id
             and w.document_type = 'core.credit_limit_change'
            where c.status = 'pending'
              and ((w.status = 'completed' and w.completed_at < p_before)
                or (w.status = 'cancelled' and w.cancelled_at < p_before))
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
