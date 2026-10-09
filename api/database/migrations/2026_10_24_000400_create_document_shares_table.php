<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// TPL-04, TEN-01: a document shared through a public link (WhatsApp).
// One document per link; the 48-character token is random and stored only
// as its sha256 (no ids in the URL); the link expires after 7 days and
// can be withdrawn (revoked_at), never deleted. Opens are counted here and
// each one is audited. The public route finds the tenant of a token hash
// through a security-definer function (ADR 002), then reads the row under
// row-level security.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('document_type', 60);
            $table->uuid('record_id');
            $table->uuid('company_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('location_id')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->integer('access_count')->default(0);
            $table->timestampTz('last_accessed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'document_type', 'record_id']);
        });

        DB::statement('alter table document_shares add constraint document_shares_access_count_check check (access_count >= 0)');
        Rls::enable('document_shares');

        // The tenant of a share token hash, across tenants, whatever its
        // state (the controller tells expired from active). Runs as the
        // schema owner and returns nothing but the id.
        DB::unprepared(<<<'SQL'
            create or replace function public.document_share_tenant(p_token_hash text) returns uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select s.tenant_id from public.document_shares s
                where s.token_hash = p_token_hash
                limit 1
            $$;

            revoke all on function public.document_share_tenant(text) from public;
            SQL);

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("grant execute on function public.document_share_tenant(text) to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.document_share_tenant(text)');
        Schema::dropIfExists('document_shares');
    }
};
