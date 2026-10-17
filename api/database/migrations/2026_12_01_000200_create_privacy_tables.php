<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// NFR-08, TEN-01, TEN-06: data protection (docs/privacy.md).
//
// - privacy_requests: a data subject's request about their personal data
//   (access, correction, deletion by anonymisation), with its legal
//   deadline, approval and outcome. Never deleted; archived.
// - consent_records: marketing consent, append-only. A change is a new row;
//   the current state is the latest row per subject, purpose and channel.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            // customer | employee | user (PrivacySubjects)
            $table->string('subject_type', 30);
            $table->uuid('subject_id');
            // access | correction | deletion
            $table->string('kind', 20);
            // received | approved | completed | rejected
            $table->string('status', 20)->default('received');
            $table->text('note')->nullable();
            // Correction: the new values asked for, by field.
            $table->jsonb('corrections')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('due_at');
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignUuid('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            // What was done: tables and columns changed and kept, never personal values.
            $table->jsonb('result')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status', 'due_at']);
            $table->index(['tenant_id', 'subject_type', 'subject_id']);
        });

        DB::statement("alter table privacy_requests add constraint privacy_requests_kind_check check (kind in ('access', 'correction', 'deletion'))");
        DB::statement("alter table privacy_requests add constraint privacy_requests_status_check check (status in ('received', 'approved', 'completed', 'rejected'))");
        DB::statement("alter table privacy_requests add constraint privacy_requests_corrections_check check (corrections is null or jsonb_typeof(corrections) = 'object')");
        Rls::enable('privacy_requests');

        Schema::create('consent_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            // customer (a party)
            $table->string('subject_type', 30);
            $table->uuid('subject_id');
            // marketing
            $table->string('purpose', 40);
            // email | sms | whatsapp | any
            $table->string('channel', 20);
            $table->boolean('granted');
            // back_office | storefront | import | lead_form
            $table->string('source', 20);
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
            $table->string('evidence', 500)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'subject_type', 'subject_id', 'purpose', 'channel', 'recorded_at']);
        });

        DB::statement("alter table consent_records add constraint consent_records_channel_check check (channel in ('email', 'sms', 'whatsapp', 'any'))");
        DB::statement("alter table consent_records add constraint consent_records_source_check check (source in ('back_office', 'storefront', 'import', 'lead_form'))");
        Rls::enable('consent_records');

        // Append-only: no role (the owner included) edits or deletes a consent record.
        DB::unprepared(<<<'SQL'
            create or replace function public.consent_records_append_only() returns trigger
            language plpgsql
            set search_path = pg_catalog, public
            as $fn$
            begin
                raise exception 'consent records are append-only';
            end
            $fn$;

            create trigger consent_records_append_only
                before update or delete on public.consent_records
                for each row execute function public.consent_records_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
        DB::unprepared('drop function if exists public.consent_records_append_only()');
        Schema::dropIfExists('privacy_requests');
    }
};
