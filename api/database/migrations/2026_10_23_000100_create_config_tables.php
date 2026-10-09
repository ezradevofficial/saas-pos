<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// LAY-06, LAY-07, TEN-01: versioned JSON configuration (themes, dashboards,
// navigation, form layouts, list views, POS layouts, templates).
//
// config_documents: one document per tenant, kind, key and scope (tenant,
// company, branch, location, role or user). scope_id is null for the tenant
// scope; for the others it names a row of this tenant (checked by the API,
// since it points at different tables and cannot carry a foreign key).
// config_versions: its versions. A draft is edited; publishing makes it
// immutable (trigger below); at most one draft and one published version
// per document. Workflows keep their own tables (docs/adr/010).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('kind', 60);
            $table->string('key', 100);
            $table->string('scope_type', 20);
            $table->uuid('scope_id')->nullable();
            $table->string('name', 150);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'kind', 'key']);
            $table->index(['scope_type', 'scope_id']);
        });

        DB::statement("alter table config_documents add constraint config_documents_scope_type_check check (scope_type in ('tenant', 'company', 'branch', 'location', 'role', 'user'))");
        DB::statement("alter table config_documents add constraint config_documents_scope_id_check check ((scope_type = 'tenant') = (scope_id is null))");
        DB::statement("create unique index config_documents_scope_unique on config_documents (tenant_id, kind, key, scope_type, coalesce(scope_id, '00000000-0000-0000-0000-000000000000'::uuid))");
        Rls::enable('config_documents');

        Schema::create('config_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('document_id')->constrained('config_documents')->restrictOnDelete();
            $table->integer('version');
            $table->string('status', 20);
            $table->jsonb('payload');
            // draft | copy | rollback
            $table->string('source', 20);
            $table->uuid('source_version_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampTz('discarded_at')->nullable();
            $table->foreignUuid('discarded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['document_id', 'version']);
        });

        Schema::table('config_versions', function (Blueprint $table) {
            $table->foreign('source_version_id')->references('id')->on('config_versions')->restrictOnDelete();
        });

        DB::statement("alter table config_versions add constraint config_versions_status_check check (status in ('draft', 'published', 'archived'))");
        DB::statement('alter table config_versions add constraint config_versions_version_check check (version >= 1)');
        // Published (or once published) unless it is a discarded draft, which never was.
        DB::statement("alter table config_versions add constraint config_versions_published_check check (status = 'draft' or published_at is not null or discarded_at is not null)");
        DB::statement("alter table config_versions add constraint config_versions_discarded_check check (discarded_at is null or (status = 'archived' and published_at is null))");
        DB::statement("create unique index config_versions_one_draft on config_versions (document_id) where status = 'draft'");
        DB::statement("create unique index config_versions_one_published on config_versions (document_id) where status = 'published'");
        Rls::enable('config_versions');

        // LAY-06: a published or archived version never changes its payload,
        // never goes back to draft, and no version is deleted.
        DB::unprepared(<<<'SQL'
            create or replace function config_versions_immutable() returns trigger
            language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'configuration versions are never deleted' using errcode = 'insufficient_privilege';
                end if;
                if old.status <> 'draft' and (new.payload is distinct from old.payload or new.status = 'draft'
                    or new.version <> old.version or new.document_id <> old.document_id) then
                    raise exception 'a published configuration version is immutable' using errcode = 'insufficient_privilege';
                end if;
                if old.status = 'archived' and new.status <> 'archived' then
                    raise exception 'an archived configuration version stays archived' using errcode = 'insufficient_privilege';
                end if;
                return new;
            end;
            $$;

            create trigger config_versions_immutable
                before update or delete on config_versions
                for each row execute function config_versions_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('config_versions');
        DB::unprepared('drop function if exists config_versions_immutable()');
        Schema::dropIfExists('config_documents');
    }
};
