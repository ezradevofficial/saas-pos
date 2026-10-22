<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// AUTH-11, ADM-01c, PAY-28 (Phase 8 review M1): a suspended tenant keeps
// approvals and payroll (EnforceSubscription), and its users keep password
// sign-in. With SSO enforced, its staff could not sign in at all, because the
// two pre-sign-in lookups answered only active tenants. They now answer
// active and suspended tenants; EnforceSubscription still limits what a
// suspended tenant may do once signed in. Closed or other tenants stay out.
// Same signatures, owners and grants (ADR 002, SecurityDefinerFunctionsTest).
return new class extends Migration
{
    public function up(): void
    {
        $this->replace("('active', 'suspended')");
    }

    public function down(): void
    {
        $this->replace("('active')");
    }

    private function replace(string $statuses): void
    {
        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        foreach ([
            // Email discovery and sign-up's enforced-domain check: the active or
            // suspended tenant that proved this email domain.
            'sso_tenant_for_email_domain(p_domain text)' => ['text', <<<SQL
                select d.tenant_id from public.sso_domains d
                join public.tenants t on t.id = d.tenant_id and t.status in {$statuses}
                where d.domain = lower(p_domain) and d.status = 'verified' and d.archived_at is null
                SQL],
            // The sign-in page's host (BR-04): the active or suspended tenant whose
            // subdomain slug or verified custom domain it is.
            'sso_tenant_for_host(p_slug text, p_host text)' => ['text, text', <<<SQL
                select t.id from public.tenants t
                where t.status in {$statuses}
                  and ((p_slug is not null and t.slug = lower(p_slug))
                    or (p_host is not null and exists (
                        select 1 from public.tenant_domains d
                        where d.tenant_id = t.id and d.host = lower(p_host)
                          and d.status = 'verified' and d.archived_at is null)))
                order by t.id
                limit 1
                SQL],
        ] as $signature => [$types, $body]) {
            $name = strstr($signature, '(', true);

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
};
