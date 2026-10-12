<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SUB-01, ADM-07 (minimal), AUD-01: platform staff, their bearer tokens and
// their append-only, hash-chained audit log. Global tables with no tenant_id
// and no row-level security (ADR 013): staff are not tenant users and
// belong to no tenant. They never read tenant rows through these tables;
// cross-tenant work (later phases) enters a tenant with TenantContext::run.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email');
            $table->string('password')->nullable();
            $table->string('role', 20);
            $table->string('status', 20)->default('pending');
            // Encrypted by the model; two-factor is required (no full token without it).
            $table->text('two_factor_secret')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->bigInteger('two_factor_last_used_step')->nullable();
            $table->smallInteger('failed_sign_ins')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_sign_in_at')->nullable();
            // The one-time setup link (platform:user): only its sha256 is stored.
            $table->char('setup_token_hash', 64)->nullable()->unique();
            $table->timestampTz('setup_expires_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('alter table platform_users alter column email type citext');
        DB::statement('alter table platform_users add constraint platform_users_email_unique unique (email)');
        DB::statement("alter table platform_users add constraint platform_users_role_check check (role in ('owner', 'billing', 'support'))");
        DB::statement("alter table platform_users add constraint platform_users_status_check check (status in ('pending', 'active', 'deactivated'))");

        Schema::create('platform_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('platform_user_id')->constrained('platform_users')->restrictOnDelete();
            $table->char('token', 64)->unique();
            $table->jsonb('abilities');
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();
        });

        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('seq')->unique();
            $table->timestampTz('occurred_at', 6);
            $table->uuid('platform_user_id')->nullable()->index();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->char('prev_hash', 64);
            $table->char('hash', 64);

            $table->index(['subject_type', 'subject_id']);
        });

        // AUD-03 for staff: nobody, the schema owner included, edits or removes entries.
        DB::unprepared(<<<'SQL'
            create or replace function platform_audit_logs_append_only() returns trigger
            language plpgsql as $$
            begin
                raise exception 'platform audit log is append-only' using errcode = 'insufficient_privilege';
            end;
            $$;

            create trigger platform_audit_logs_append_only
                before update or delete on platform_audit_logs
                for each row execute function platform_audit_logs_append_only();

            create trigger platform_audit_logs_no_truncate
                before truncate on platform_audit_logs
                for each statement execute function platform_audit_logs_append_only();
            SQL);

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("revoke update, delete, truncate on platform_audit_logs from {$runtimeRole}");
        // Staff are deactivated, never deleted (their audit entries name them).
        DB::statement("revoke delete, truncate on platform_users from {$runtimeRole}");
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
        Schema::dropIfExists('platform_access_tokens');
        Schema::dropIfExists('platform_users');
        DB::statement('drop function if exists platform_audit_logs_append_only()');
    }
};
