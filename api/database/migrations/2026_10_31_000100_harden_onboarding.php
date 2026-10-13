<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ONB-01, ONB-04, ONB-08 (review fixes):
// - onboarding_states.trial_expired_at: when the expired trial's modules
//   were switched off (TrialExpiry), so the scheduler handles it once;
// - scheduler lookups (ADR 002: owner-owned security-definer functions that
//   return only tenant ids, called on the runtime connection):
//   * trials past their end not yet expired;
//   * self sign-ups whose owner never verified, created before a time;
//   * assisted tenants not handed over, created before a time;
//   * the pending assisted tenant already created for a contact (email or
//     phone), so staff retrying a create get the same tenant.
return new class extends Migration
{
    /** @var array<string, string> signature => body */
    private const FUNCTIONS = [
        'app_tenants_with_expired_trials(p_at timestamptz)' => <<<'SQL'
            select s.tenant_id from public.onboarding_states s
            join public.tenants t on t.id = s.tenant_id and t.status = 'active'
            where s.trial_ends_at <= p_at and s.trial_expired_at is null
            order by 1
            SQL,
        'app_tenants_pending_verification(p_before timestamptz)' => <<<'SQL'
            select t.id from public.tenants t
            where t.status = 'active' and t.created_at < p_before
              and exists (select 1 from public.users u where u.tenant_id = t.id and u.status = 'pending')
              and not exists (select 1 from public.users u where u.tenant_id = t.id and u.status <> 'pending')
            order by 1
            SQL,
        'app_tenants_awaiting_handover(p_before timestamptz)' => <<<'SQL'
            select s.tenant_id from public.onboarding_states s
            join public.tenants t on t.id = s.tenant_id and t.status = 'active'
            where s.assisted is not null and s.assisted->>'handed_over_at' is null and s.created_at < p_before
            order by 1
            SQL,
        'app_assisted_tenant_for_contact(p_contact text)' => <<<'SQL'
            select s.tenant_id from public.onboarding_states s
            join public.tenants t on t.id = s.tenant_id and t.status = 'active'
            where s.assisted is not null and s.assisted->>'handed_over_at' is null
              and lower(s.assisted->>'contact') = lower(p_contact)
            order by s.created_at desc
            limit 1
            SQL,
    ];

    public function up(): void
    {
        Schema::table('onboarding_states', function (Blueprint $table) {
            $table->timestampTz('trial_expired_at')->nullable();
        });

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

        Schema::table('onboarding_states', function (Blueprint $table) {
            $table->dropColumn('trial_expired_at');
        });
    }

    private function types(string $signature): string
    {
        $args = trim(substr($signature, strpos($signature, '(') + 1, -1));

        return $args === '' ? '' : implode(', ', array_map(fn ($arg) => trim(explode(' ', trim($arg), 2)[1]), explode(',', $args)));
    }
};
