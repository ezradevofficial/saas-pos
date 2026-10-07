<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// AUD-01..AUD-03: append-only, hash-chained audit log, one chain per tenant.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->bigInteger('seq');
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('device_time', 6)->nullable();
            $table->uuid('user_id')->nullable();
            $table->uuid('on_behalf_of_user_id')->nullable();
            $table->string('action');
            $table->string('module');
            $table->string('auditable_type')->nullable();
            $table->uuid('auditable_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('device_id')->nullable();
            $table->uuid('location_id')->nullable();
            $table->char('prev_hash', 64);
            $table->char('hash', 64);

            $table->unique(['tenant_id', 'seq']);
            $table->index(['tenant_id', 'auditable_type', 'auditable_id']);
            $table->index(['tenant_id', 'occurred_at']);
        });

        Rls::enable('audit_logs');

        // The last seq and hash of each tenant's chain; its row is locked
        // FOR UPDATE while an entry is appended, serialising writers.
        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->tenantId()->primary();
            $table->bigInteger('seq');
            $table->char('hash', 64);
        });

        Rls::enable('audit_chain_heads');

        // AUD-03: nobody, the schema owner included, edits or removes entries.
        DB::unprepared(<<<'SQL'
            create or replace function audit_logs_append_only() returns trigger
            language plpgsql as $$
            begin
                raise exception 'audit log is append-only' using errcode = 'insufficient_privilege';
            end;
            $$;

            create trigger audit_logs_append_only
                before update or delete on audit_logs
                for each row execute function audit_logs_append_only();

            create trigger audit_logs_no_truncate
                before truncate on audit_logs
                for each statement execute function audit_logs_append_only();
            SQL);

        // Default privileges grant the runtime role SELECT, INSERT, UPDATE and
        // DELETE; take back UPDATE and DELETE. TRUNCATE is never granted by
        // default; revoking it too is defensive. The runtime role is the
        // `pgsql` connection user.
        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("revoke update, delete, truncate on audit_logs from {$runtimeRole}");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_heads');
        Schema::dropIfExists('audit_logs');
        DB::statement('drop function if exists audit_logs_append_only()');
    }
};
