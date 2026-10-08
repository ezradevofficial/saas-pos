<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// WF-01..WF-11, APR-09: process flows.
//
// workflow_definitions: one flow per tenant, document type and company
// (company_id null: every company without its own flow).
// workflow_versions: its versions; a draft is edited, publishing makes it
// immutable (trigger below), at most one draft and one published version
// per flow. Documents in progress stay on the version they started with.
//
// document_workflows: a document's run of a flow version.
// document_workflow_tokens: where the document is (one row per position, so
// parallel branches are several active rows); left rows are kept.
// document_workflow_events: the history (append-only, trigger below).
// document_workflow_links: documents a flow created (WF-07), with what
// happens to them when the flow is cancelled (WF-11).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('document_type', 100);
            $table->foreignUuid('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'document_type']);
        });

        // One flow per (tenant, type, company); "all companies" is one too.
        DB::statement("create unique index workflow_definitions_scope_unique on workflow_definitions (tenant_id, document_type, coalesce(company_id, '00000000-0000-0000-0000-000000000000'::uuid))");
        Rls::enable('workflow_definitions');

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('definition_id')->constrained('workflow_definitions')->restrictOnDelete();
            $table->integer('version');
            $table->string('status', 20);
            $table->jsonb('graph');
            // default | blank | copy | rollback | restore_default | draft
            $table->string('source', 20);
            $table->uuid('source_version_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['definition_id', 'version']);
        });

        Schema::table('workflow_versions', function (Blueprint $table) {
            $table->foreign('source_version_id')->references('id')->on('workflow_versions')->restrictOnDelete();
        });

        DB::statement("alter table workflow_versions add constraint workflow_versions_status_check check (status in ('draft', 'published', 'archived'))");
        DB::statement('alter table workflow_versions add constraint workflow_versions_version_check check (version >= 1)');
        DB::statement("alter table workflow_versions add constraint workflow_versions_published_check check (status = 'draft' or published_at is not null)");
        DB::statement("create unique index workflow_versions_one_draft on workflow_versions (definition_id) where status = 'draft'");
        DB::statement("create unique index workflow_versions_one_published on workflow_versions (definition_id) where status = 'published'");
        Rls::enable('workflow_versions');

        // APR-09: a published or archived version never changes its graph,
        // never goes back to draft, and no version is deleted.
        DB::unprepared(<<<'SQL'
            create or replace function workflow_versions_immutable() returns trigger
            language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'workflow versions are never deleted' using errcode = 'insufficient_privilege';
                end if;
                if old.status <> 'draft' and (new.graph is distinct from old.graph or new.status = 'draft'
                    or new.version <> old.version or new.definition_id <> old.definition_id) then
                    raise exception 'a published workflow version is immutable' using errcode = 'insufficient_privilege';
                end if;
                if old.status = 'archived' and new.status <> 'archived' then
                    raise exception 'an archived workflow version stays archived' using errcode = 'insufficient_privilege';
                end if;
                return new;
            end;
            $$;

            create trigger workflow_versions_immutable
                before update or delete on workflow_versions
                for each row execute function workflow_versions_immutable();
            SQL);

        Schema::create('document_workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('document_type', 100);
            $table->uuid('document_id');
            // The document's place when the flow started (lists, overdue queries);
            // permission checks read the type's scope accessor each time.
            $table->foreignUuid('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('version_id')->constrained('workflow_versions')->restrictOnDelete();
            $table->string('status', 20);
            // The `outcome` of the end node reached (approved, rejected, ...).
            $table->string('outcome', 50)->nullable();
            $table->foreignUuid('started_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'document_type', 'document_id']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement("alter table document_workflows add constraint document_workflows_status_check check (status in ('running', 'completed', 'cancelled'))");
        // One running flow per document.
        DB::statement("create unique index document_workflows_one_running on document_workflows (tenant_id, document_type, document_id) where status = 'running'");
        Rls::enable('document_workflows');

        Schema::create('document_workflow_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('workflow_id')->constrained('document_workflows')->restrictOnDelete();
            $table->string('node_id', 64);
            // active: at a stage or approval; waiting: arrived at a join; done; cancelled.
            $table->string('status', 20);
            // Parallel groups this position belongs to, innermost last (WF-06).
            $table->jsonb('groups')->default('[]');
            $table->timestampTz('entered_at');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->foreignUuid('entered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('left_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['workflow_id', 'status']);
        });

        DB::statement("alter table document_workflow_tokens add constraint document_workflow_tokens_status_check check (status in ('active', 'waiting', 'done', 'cancelled'))");
        // WF-09: the overdue query.
        DB::statement("create index document_workflow_tokens_due on document_workflow_tokens (tenant_id, due_at) where status = 'active' and due_at is not null");
        Rls::enable('document_workflow_tokens');

        Schema::create('document_workflow_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('workflow_id')->constrained('document_workflows')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('node_id', 64)->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->jsonb('data')->default('{}');
            $table->timestampTz('occurred_at', 6);

            $table->index(['workflow_id', 'occurred_at']);
        });

        Rls::enable('document_workflow_events');

        // WF-10: the history is append-only.
        DB::unprepared(<<<'SQL'
            create or replace function document_workflow_events_append_only() returns trigger
            language plpgsql as $$
            begin
                raise exception 'workflow history is append-only' using errcode = 'insufficient_privilege';
            end;
            $$;

            create trigger document_workflow_events_append_only
                before update or delete on document_workflow_events
                for each row execute function document_workflow_events_append_only();
            SQL);

        Schema::create('document_workflow_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('workflow_id')->constrained('document_workflows')->restrictOnDelete();
            $table->string('node_id', 64);
            $table->string('mapping', 64);
            $table->string('target_type', 100);
            $table->uuid('target_document_id');
            // WF-11: keep | cancel when the source flow is cancelled.
            $table->string('on_cancel', 10);
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index('workflow_id');
        });

        DB::statement("alter table document_workflow_links add constraint document_workflow_links_on_cancel_check check (on_cancel in ('keep', 'cancel'))");
        Rls::enable('document_workflow_links');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_workflow_links');
        Schema::dropIfExists('document_workflow_events');
        DB::unprepared('drop function if exists document_workflow_events_append_only()');
        Schema::dropIfExists('document_workflow_tokens');
        Schema::dropIfExists('document_workflows');
        Schema::dropIfExists('workflow_versions');
        DB::unprepared('drop function if exists workflow_versions_immutable()');
        Schema::dropIfExists('workflow_definitions');
    }
};
