<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// LAY-06, AUD-01: review hardening of the versioned configuration store
// (docs/adr/010).
//
// - config_versions.revision: the draft's edit counter. Every draft save
//   raises it; a new draft starts above every revision the document has
//   had, so a revision names one state of one draft. Saves and publishing
//   name the revision they edited or reviewed (409 config_changed else).
// - config_versions_immutable also freezes who and when a version was
//   created and published, and where it came from, once it is no longer a
//   draft; TRUNCATE is refused (a statement trigger).
// - config_documents: kind, key and scope never change after insert.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('config_versions', function (Blueprint $table) {
            $table->integer('revision')->default(1);
        });

        DB::statement('alter table config_versions add constraint config_versions_revision_check check (revision >= 1)');

        DB::unprepared(<<<'SQL'
            create or replace function config_versions_immutable() returns trigger
            language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'configuration versions are never deleted' using errcode = 'insufficient_privilege';
                end if;
                if old.status <> 'draft' and (new.payload is distinct from old.payload or new.status = 'draft'
                    or new.version <> old.version or new.document_id <> old.document_id
                    or new.revision <> old.revision
                    or new.published_at is distinct from old.published_at
                    or new.published_by is distinct from old.published_by
                    or new.source_version_id is distinct from old.source_version_id
                    or new.created_by is distinct from old.created_by) then
                    raise exception 'a published configuration version is immutable' using errcode = 'insufficient_privilege';
                end if;
                if old.status = 'archived' and new.status <> 'archived' then
                    raise exception 'an archived configuration version stays archived' using errcode = 'insufficient_privilege';
                end if;
                return new;
            end;
            $$;

            create or replace function config_no_truncate() returns trigger
            language plpgsql as $$
            begin
                raise exception 'configuration is never truncated' using errcode = 'insufficient_privilege';
            end;
            $$;

            create trigger config_versions_no_truncate
                before truncate on config_versions
                for each statement execute function config_no_truncate();

            create trigger config_documents_no_truncate
                before truncate on config_documents
                for each statement execute function config_no_truncate();

            create or replace function config_documents_identity() returns trigger
            language plpgsql as $$
            begin
                if new.kind <> old.kind or new.key <> old.key or new.scope_type <> old.scope_type
                    or new.scope_id is distinct from old.scope_id or new.tenant_id <> old.tenant_id then
                    raise exception 'a configuration document keeps its kind, key and scope' using errcode = 'insufficient_privilege';
                end if;
                return new;
            end;
            $$;

            create trigger config_documents_identity
                before update on config_documents
                for each row execute function config_documents_identity();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists config_documents_identity on config_documents;
            drop function if exists config_documents_identity();
            drop trigger if exists config_documents_no_truncate on config_documents;
            drop trigger if exists config_versions_no_truncate on config_versions;
            drop function if exists config_no_truncate();

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
            SQL);

        DB::statement('alter table config_versions drop constraint if exists config_versions_revision_check');

        Schema::table('config_versions', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
