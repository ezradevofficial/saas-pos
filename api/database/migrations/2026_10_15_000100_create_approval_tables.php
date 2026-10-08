<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// APR-01..APR-09: approvals on workflow approval nodes.
//
// approval_requests: one per entry of a document into an approval node (a
// workflow position), with the node's configuration as published in the
// flow version the document runs on (APR-09), the document's place and
// summary (lists, search, oversight), and the timers of reminders and
// escalation (APR-05).
// approval_assignments: who must act, per step of a sequential chain;
// decided rows keep who decided and on whose behalf (APR-06).
// approval_actions: the request's history (append-only).
// approval_attachments: files on the media disk (APR-03).
// approval_delegations: a user's delegation of approvals for a date range (APR-06).
// approval_email_tokens: single-use approve/reject links, hashed (APR-08).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('workflow_id')->constrained('document_workflows')->restrictOnDelete();
            $table->foreignUuid('token_id')->unique()->constrained('document_workflow_tokens')->restrictOnDelete();
            $table->foreignUuid('version_id')->constrained('workflow_versions')->restrictOnDelete();
            $table->string('node_id', 64);
            $table->string('node_name', 255)->nullable();
            $table->string('document_type', 100);
            $table->uuid('document_id');
            $table->foreignUuid('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('document_number', 100)->nullable();
            $table->string('document_title', 255)->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->foreignUuid('requester_id')->nullable()->constrained('users')->restrictOnDelete();
            // The node's normalised `approval`, `escalation`, `reminders` and `due` (ApprovalConfig).
            $table->jsonb('config');
            $table->string('mode', 10);
            $table->smallInteger('step')->default(0);
            $table->smallInteger('steps')->default(1);
            $table->string('status', 20);
            $table->string('blocked_reason', 50)->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('level_started_at');
            $table->smallInteger('escalation_level')->default(0);
            // next_level escalation: how many levels above the document's place were reached.
            $table->smallInteger('escalated_depth')->default(0);
            $table->timestampTz('escalate_at')->nullable();
            $table->smallInteger('reminders_sent')->default(0);
            $table->timestampTz('next_reminder_at')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->boolean('auto_decided')->default(false);
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status', 'received_at']);
            $table->index('workflow_id');
        });

        DB::statement("alter table approval_requests add constraint approval_requests_status_check check (status in ('pending', 'approved', 'rejected', 'returned', 'cancelled', 'expired'))");
        DB::statement("alter table approval_requests add constraint approval_requests_mode_check check (mode in ('any', 'all', 'majority'))");
        DB::statement('alter table approval_requests add constraint approval_requests_amount_check check ((amount_minor is null) = (currency is null))');
        // APR-05: the timer query.
        DB::statement("create index approval_requests_timers on approval_requests (tenant_id, least(escalate_at, next_reminder_at)) where status = 'pending'");
        Rls::enable('approval_requests');

        Schema::create('approval_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->smallInteger('step')->default(0);
            // resolved | fallback (next eligible approver, APR-07) | escalated | reassigned
            $table->string('source', 20);
            $table->foreignUuid('reassigned_from')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('reassigned_by')->nullable()->constrained('users')->restrictOnDelete();
            // pending | approved | rejected | returned | closed | reassigned
            $table->string('status', 20);
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('on_behalf_of')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestampsTz();

            $table->index(['request_id', 'status']);
            $table->index(['tenant_id', 'user_id', 'status']);
        });

        DB::statement("alter table approval_assignments add constraint approval_assignments_status_check check (status in ('pending', 'approved', 'rejected', 'returned', 'closed', 'reassigned'))");
        DB::statement("alter table approval_assignments add constraint approval_assignments_source_check check (source in ('resolved', 'fallback', 'escalated', 'reassigned'))");
        DB::statement("create unique index approval_assignments_one_pending on approval_assignments (request_id, user_id) where status = 'pending'");
        Rls::enable('approval_assignments');

        Schema::create('approval_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->foreignUuid('assignment_id')->nullable()->constrained('approval_assignments')->restrictOnDelete();
            $table->string('type', 30);
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('on_behalf_of')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->jsonb('data')->default('{}');
            $table->timestampTz('occurred_at', 6);

            $table->index(['request_id', 'occurred_at']);
        });

        Rls::enable('approval_actions');

        DB::unprepared(<<<'SQL'
            create or replace function approval_actions_append_only() returns trigger
            language plpgsql as $$
            begin
                raise exception 'approval history is append-only' using errcode = 'insufficient_privilege';
            end;
            $$;

            create trigger approval_actions_append_only
                before update or delete on approval_actions
                for each row execute function approval_actions_append_only();
            SQL);

        Schema::create('approval_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->foreignUuid('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('disk', 30);
            $table->string('path', 255)->unique();
            $table->string('name', 255);
            $table->string('mime', 150);
            $table->bigInteger('size');
            $table->timestampsTz();

            $table->index('request_id');
        });

        Rls::enable('approval_attachments');

        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('from_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('to_user_id')->constrained('users')->restrictOnDelete();
            // Dates in the time zone of each request's company.
            $table->date('starts_on');
            $table->date('ends_on');
            // Document type keys, or null for every type.
            $table->jsonb('document_types')->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'to_user_id']);
            $table->index(['tenant_id', 'from_user_id']);
        });

        DB::statement('alter table approval_delegations add constraint approval_delegations_users_check check (from_user_id <> to_user_id)');
        DB::statement('alter table approval_delegations add constraint approval_delegations_dates_check check (ends_on >= starts_on)');
        Rls::enable('approval_delegations');

        Schema::create('approval_email_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('assignment_id')->constrained('approval_assignments')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 10);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();

            $table->index('assignment_id');
        });

        DB::statement("alter table approval_email_tokens add constraint approval_email_tokens_action_check check (action in ('approve', 'reject'))");
        Rls::enable('approval_email_tokens');

        // APR-08: an email link is opened without a session: find the tenant
        // of a token hash (nothing else), then read the token under RLS.
        DB::unprepared(<<<'SQL'
            create or replace function public.approval_tenant_for_email_token(p_token_hash text) returns uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select t.tenant_id from public.approval_email_tokens t
                where t.token_hash = p_token_hash
                limit 1
            $$;

            revoke all on function approval_tenant_for_email_token(text) from public;
            SQL);

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("grant execute on function approval_tenant_for_email_token(text) to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists approval_tenant_for_email_token(text)');
        Schema::dropIfExists('approval_email_tokens');
        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('approval_attachments');
        Schema::dropIfExists('approval_actions');
        DB::unprepared('drop function if exists approval_actions_append_only()');
        Schema::dropIfExists('approval_assignments');
        Schema::dropIfExists('approval_requests');
    }
};
