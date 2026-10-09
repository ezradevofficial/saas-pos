<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// CF-01, CF-02, CF-06: custom field definitions per registered entity
// (items, parties, later custom forms), the files of file fields, and a
// `custom jsonb` value column on parties (items have one already).
//
// Values are filtered through one generic index per entity table, a GIN
// `jsonb_path_ops` index answering `custom @> {"key": value}`, so adding a
// field never needs a deploy or a migration (CF-06). Range filters and
// sorts read `custom ->> 'key'`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('entity', 40);
            $table->string('key', 40);
            $table->string('type', 20);
            // CF-02: one label, as the admin types it (single-language tenant text).
            $table->string('label', 100);
            $table->string('help', 255)->nullable();
            $table->jsonb('default_value')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('is_unique')->default(false);
            $table->decimal('min_value', 30, 6)->nullable();
            $table->decimal('max_value', 30, 6)->nullable();
            $table->string('pattern', 255)->nullable();
            $table->jsonb('options')->default('[]');
            $table->string('lookup_target', 40)->nullable();
            $table->text('formula')->nullable();
            $table->string('formula_type', 10)->nullable();
            // RBAC-05: role ids; empty means everyone who reaches the record.
            $table->jsonb('visible_roles')->default('[]');
            $table->jsonb('editable_roles')->default('[]');
            $table->boolean('show_on_pos')->default(false);
            $table->integer('position')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            // A key is never reused, even once archived: stored values keep their meaning.
            $table->unique(['tenant_id', 'entity', 'key']);
            $table->index(['tenant_id', 'entity', 'position']);
        });

        DB::statement("alter table custom_field_definitions add constraint custom_field_definitions_type_check check (type in ('text', 'long_text', 'number', 'money', 'date', 'datetime', 'boolean', 'select', 'multi_select', 'file', 'lookup', 'formula'))");
        DB::statement("alter table custom_field_definitions add constraint custom_field_definitions_key_check check (key ~ '^[a-z][a-z0-9_]{0,39}$')");
        DB::statement("alter table custom_field_definitions add constraint custom_field_definitions_json_check check (jsonb_typeof(options) = 'array' and jsonb_typeof(visible_roles) = 'array' and jsonb_typeof(editable_roles) = 'array')");
        DB::statement("alter table custom_field_definitions add constraint custom_field_definitions_formula_check check ((type = 'formula') = (formula is not null) and (formula_type is null or formula_type in ('number', 'text', 'boolean')))");
        Rls::enable('custom_field_definitions');

        // CF-01: a file field's files on the media disk. Uploaded before the
        // record is saved (record_id null), then tied to the record on save.
        Schema::create('custom_field_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('entity', 40);
            $table->string('field_key', 40);
            $table->uuid('record_id')->nullable();
            $table->string('disk', 20);
            $table->string('path')->unique();
            $table->string('name');
            $table->string('mime', 100);
            $table->integer('size');
            $table->foreignUuid('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'entity', 'record_id']);
        });

        Rls::enable('custom_field_files');

        Schema::table('parties', function (Blueprint $table) {
            $table->jsonb('custom')->default('{}');
        });

        DB::statement("alter table parties add constraint parties_custom_check check (jsonb_typeof(custom) = 'object')");
        DB::statement('create index parties_custom_gin on parties using gin (custom jsonb_path_ops)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists parties_custom_gin');
        Schema::table('parties', fn (Blueprint $table) => $table->dropColumn('custom'));
        Schema::dropIfExists('custom_field_files');
        Schema::dropIfExists('custom_field_definitions');
    }
};
