<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// EXP-02, TEN-01: full tenant data exports requested by the Owner.
//
// - tenant_exports: one row per request. The zip lives on the media disk
//   at `tenants/{tenant}/exports/{id}.zip`; the row keeps its size, SHA-256
//   and counts. Rows are never deleted (TEN-06): an expired export keeps
//   its row with status `expired` and no file.
// - At most one export is queued or running per tenant (partial unique
//   index), whatever the application does.
// - app_tenants_with_due_exports(at, running_before, queued_before) finds,
//   before any tenant is set (ADR 002), the tenants with a ready export
//   past its expiry, a build running since before running_before (a dead
//   worker), one queued since before queued_before (no worker), or a
//   failed one whose file could not be deleted yet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            // queued | running | ready | failed | expired
            $table->string('status', 20)->default('queued');
            $table->string('disk', 40)->nullable();
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('table_count')->nullable();
            $table->unsignedBigInteger('row_count')->nullable();
            $table->unsignedInteger('file_count')->nullable();
            $table->string('failure', 60)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('downloaded_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
        });

        DB::statement("alter table tenant_exports add constraint tenant_exports_status_check check (status in ('queued', 'running', 'ready', 'failed', 'expired'))");
        DB::statement("alter table tenant_exports add constraint tenant_exports_ready_check check (status <> 'ready' or (path is not null and expires_at is not null))");
        DB::statement("create unique index tenant_exports_one_active on tenant_exports (tenant_id) where status in ('queued', 'running')");
        Rls::enable('tenant_exports');

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        DB::unprepared(<<<'SQL'
            create or replace function public.app_tenants_with_due_exports(p_at timestamptz, p_running_before timestamptz, p_queued_before timestamptz) returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $fn$
                select distinct e.tenant_id from public.tenant_exports e
                where (e.status = 'ready' and e.expires_at <= p_at)
                   or (e.status = 'running' and e.started_at <= p_running_before)
                   or (e.status = 'queued' and e.created_at <= p_queued_before)
                   or (e.status = 'failed' and e.path is not null)
                order by 1
            $fn$;

            revoke all on function public.app_tenants_with_due_exports(timestamptz, timestamptz, timestamptz) from public;
            SQL);

        DB::statement("grant execute on function public.app_tenants_with_due_exports(timestamptz, timestamptz, timestamptz) to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::unprepared('drop function if exists public.app_tenants_with_due_exports(timestamptz, timestamptz, timestamptz)');
        Schema::dropIfExists('tenant_exports');
    }
};
