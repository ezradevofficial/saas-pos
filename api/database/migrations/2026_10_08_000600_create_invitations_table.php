<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// AUTH-05: invitations, the cross-tenant invitation lookup, and role names
// unique among a tenant's active roles (RBAC-02).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('email')->nullable();
            $table->string('phone', 16)->nullable();
            $table->string('name');
            // [{role_id, scope_type, scope_id}], re-checked when accepted.
            $table->jsonb('assignments');
            // sha256 hex of the 40-character token sent to the invitee.
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('invited_by');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'invited_by'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->index('email');
            $table->index('phone');
        });

        DB::statement('alter table invitations alter column email type citext');
        DB::statement('alter table invitations add constraint invitations_email_or_phone_check check (email is not null or phone is not null)');
        Rls::enable('invitations');

        // The tenant of an invitation token hash, across tenants, whatever
        // its state (the endpoint tells expired from unknown). Runs as the
        // schema owner and returns nothing but the id.
        DB::unprepared(<<<'SQL'
            create or replace function public.auth_tenant_for_invitation(p_token_hash text) returns uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select i.tenant_id from public.invitations i
                where i.token_hash = p_token_hash
                limit 1
            $$;

            revoke all on function auth_tenant_for_invitation(text) from public;
            SQL);

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("grant execute on function auth_tenant_for_invitation(text) to {$runtimeRole}");

        // An archived role frees its name (TEN-06).
        DB::statement('alter table roles drop constraint roles_tenant_id_name_guard_name_unique');
        DB::statement('create unique index roles_tenant_id_name_guard_name_active_unique on roles (tenant_id, name, guard_name) where archived_at is null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists roles_tenant_id_name_guard_name_active_unique');
        DB::statement('alter table roles add constraint roles_tenant_id_name_guard_name_unique unique (tenant_id, name, guard_name)');
        DB::statement('drop function if exists auth_tenant_for_invitation(text)');
        Schema::dropIfExists('invitations');
    }
};
