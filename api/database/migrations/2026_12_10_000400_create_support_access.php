<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// RBAC-13, ADM-03 (docs/modules/platform-admin.md): support access with
// consent.
// - support_access_grants (tenant table, RLS): the consent record. Staff
//   ask, the tenant's Owner or Admin approves or denies, and can revoke.
// - users.support_grant_id marks the support principal of a grant: a user
//   of the tenant with no password or contact, which cannot sign in. Its
//   token comes from the grant's one-time entry code and expires with it.
// - platform_support_requests (global, platform role only): the console's
//   mirror of each request, so staff list theirs without reading tenant
//   tables. No tenant_id (ADR 013): `tenant_ref` names the customer.
// - app_tenants_with_expired_support_grants: tenant ids only, for
//   `support:expire` (ADR 002).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_access_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            // Staff are not tenant users: their id and name are copied, never joined.
            $table->uuid('platform_user_id');
            $table->string('staff_name', 120);
            $table->string('staff_email');
            $table->text('reason');
            $table->string('mode', 5);
            $table->smallInteger('hours');
            // Payroll, HR-sensitive and payment data: off unless the tenant ticks it when approving.
            $table->boolean('include_sensitive')->default(false);
            $table->string('status', 10)->default('pending');
            $table->timestampTz('requested_at');
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('ended_at')->nullable();
            // revoked, expired, ended_by_staff, ended_in_session, cancelled
            $table->string('end_reason', 20)->nullable();
            $table->uuid('principal_user_id')->nullable();
            // The one-time code the console hands to the tenant web (sha256 of its secret).
            $table->char('entry_code_hash', 64)->nullable();
            $table->timestampTz('entry_code_expires_at')->nullable();
            $table->timestampTz('session_started_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
            $table->unique('entry_code_hash');
            // The principal's composite link below: a user can only name a grant of its own tenant.
            $table->unique(['tenant_id', 'id']);
        });

        DB::statement("alter table support_access_grants add constraint support_access_grants_mode_check check (mode in ('read', 'write'))");
        DB::statement('alter table support_access_grants add constraint support_access_grants_hours_check check (hours between 1 and 72)');
        DB::statement("alter table support_access_grants add constraint support_access_grants_status_check check (status in ('pending', 'approved', 'denied', 'cancelled', 'revoked', 'expired', 'ended'))");
        DB::statement("alter table support_access_grants add constraint support_access_grants_approved_check check (status not in ('approved', 'revoked', 'expired', 'ended') or (decided_by is not null and expires_at is not null))");
        Rls::enable('support_access_grants');

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('support_grant_id')->nullable()->unique();
            $table->foreign(['tenant_id', 'support_grant_id'])->references(['tenant_id', 'id'])->on('support_access_grants')->restrictOnDelete();
        });
        DB::statement('alter table support_access_grants add constraint support_access_grants_principal_fk foreign key (principal_user_id) references users (id) on delete restrict');

        Schema::create('platform_support_requests', function (Blueprint $table) {
            // The grant's id in the tenant.
            $table->uuid('id')->primary();
            $table->uuid('tenant_ref')->index();
            $table->string('tenant_name');
            $table->foreignUuid('platform_user_id')->constrained('platform_users')->restrictOnDelete();
            $table->text('reason');
            $table->string('mode', 5);
            $table->smallInteger('hours');
            $table->boolean('include_sensitive')->default(false);
            $table->string('status', 10);
            $table->timestampTz('requested_at');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->string('end_reason', 20)->nullable();
            $table->timestampsTz();
        });

        $runtime = $this->quoted(config('database.connections.pgsql.username') ?: 'app');
        $platform = $this->quoted(config('database.connections.pgsql_platform.username') ?: 'app_platform');
        // ADR 013: a staff table: the platform role only, never the runtime role.
        DB::statement("revoke all on platform_support_requests from {$runtime}");
        DB::statement("grant select, insert, update on platform_support_requests to {$platform}");

        DB::unprepared(<<<'SQL'
            create or replace function public.app_tenants_with_expired_support_grants(p_at timestamptz) returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select distinct g.tenant_id from public.support_access_grants g
                where g.status = 'approved' and g.expires_at <= p_at
            $$;
            revoke all on function public.app_tenants_with_expired_support_grants(timestamptz) from public;
            SQL);
        DB::statement("grant execute on function public.app_tenants_with_expired_support_grants(timestamptz) to {$runtime}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.app_tenants_with_expired_support_grants(timestamptz)');
        Schema::dropIfExists('platform_support_requests');
        DB::statement('alter table support_access_grants drop constraint if exists support_access_grants_principal_fk');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'support_grant_id']);
            $table->dropColumn('support_grant_id');
        });
        Schema::dropIfExists('support_access_grants');
    }

    private function quoted(string $role): string
    {
        return '"'.str_replace('"', '""', $role).'"';
    }
};
