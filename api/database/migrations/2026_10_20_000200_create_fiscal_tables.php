<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fiscal transmission (concept note 7.2; App\Core\Fiscal).
//
// company_fiscal_settings: one row per company: the authority driver
// (KRA eTIMS OSCU, DRC DGI e-MCF), whether transmission is on, the
// taxpayer PIN, branch id and device serial, plain settings and the
// credentials the authority issues (encrypted, never returned), and the
// company's next fiscal invoice number.
//
// fiscal_submissions: one row per document a module hands over (a sale, a
// refund, a void), queued and retried with backoff until the authority
// accepts it; the document as it was when queued (payload), the
// authority's references once accepted (receipt signature, internal data,
// QR content, control unit invoice number) and the last safe error.
// Unique per source document: an event delivered twice queues it once.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_fiscal_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->unique()->constrained()->restrictOnDelete();
            $table->char('country', 2);
            $table->string('driver', 30);
            $table->boolean('enabled')->default(false);
            $table->string('tin', 30)->nullable();
            $table->string('branch_code', 10)->nullable();
            $table->string('device_serial', 100)->nullable();
            $table->jsonb('settings')->default('{}');
            $table->text('credentials')->nullable();
            $table->timestampTz('initialized_at')->nullable();
            $table->bigInteger('next_invoice_no')->default(1);
            $table->timestampsTz();
        });

        DB::statement('alter table company_fiscal_settings add constraint company_fiscal_settings_next_check check (next_invoice_no >= 1)');
        Rls::enable('company_fiscal_settings');

        Schema::create('fiscal_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->char('country', 2);
            $table->string('driver', 30);
            $table->string('source', 30);
            $table->string('document_type', 10);
            $table->uuid('document_id');
            $table->string('document_number', 80)->nullable();
            $table->bigInteger('invoice_no');
            $table->uuid('original_submission_id')->nullable();
            $table->jsonb('payload');
            $table->string('status', 20);
            $table->integer('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('deadline_at')->nullable();
            $table->jsonb('authority')->default('{}');
            // sha256 of the last request body sent (a duplicate answer is ours only when it matches).
            $table->char('request_hash', 64)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('alerted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'source', 'document_type', 'document_id']);
            $table->unique(['company_id', 'invoice_no']);
            $table->index(['company_id', 'status', 'created_at']);
        });

        Schema::table('fiscal_submissions', function (Blueprint $table) {
            $table->foreign('original_submission_id')->references('id')->on('fiscal_submissions')->restrictOnDelete();
        });

        DB::statement("alter table fiscal_submissions add constraint fiscal_submissions_type_check check (document_type in ('sale', 'refund', 'void'))");
        DB::statement("alter table fiscal_submissions add constraint fiscal_submissions_status_check check (status in ('queued', 'sending', 'accepted', 'rejected', 'retrying', 'needs_attention'))");
        DB::statement("alter table fiscal_submissions add constraint fiscal_submissions_original_check check ((document_type = 'sale') = (original_submission_id is null))");
        DB::statement('alter table fiscal_submissions add constraint fiscal_submissions_attempts_check check (attempts >= 0 and invoice_no >= 1)');
        DB::statement("create index fiscal_submissions_due on fiscal_submissions (next_attempt_at) where status in ('queued', 'retrying')");
        DB::statement("create index fiscal_submissions_sending on fiscal_submissions (updated_at) where status = 'sending'");
        Rls::enable('fiscal_submissions');

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        // The scheduler (fiscal:process) finds tenants with a submission due
        // or left `sending` by a dead worker, before any tenant is set
        // (ADR 002): tenant ids only.
        DB::unprepared(<<<'SQL'
            create or replace function public.app_tenants_with_due_fiscal_submissions(p_at timestamptz, p_stale_before timestamptz) returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select t.tenant_id from (
                    select s.tenant_id from public.fiscal_submissions s
                    where s.status in ('queued', 'retrying') and s.next_attempt_at <= p_at
                    union
                    select s.tenant_id from public.fiscal_submissions s
                    where s.status = 'sending' and s.updated_at < p_stale_before
                ) t
                order by 1
            $$;

            revoke all on function public.app_tenants_with_due_fiscal_submissions(timestamptz, timestamptz) from public;
            SQL);

        DB::statement("grant execute on function public.app_tenants_with_due_fiscal_submissions(timestamptz, timestamptz) to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.app_tenants_with_due_fiscal_submissions(timestamptz, timestamptz)');
        Schema::dropIfExists('fiscal_submissions');
        Schema::dropIfExists('company_fiscal_settings');
    }
};
