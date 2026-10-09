<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// CF-04, CF-05: custom forms. A tenant's form types (name typed once,
// numbering, workflow on or off, a line table, attachments, the roles that
// may use it), their records at a company (and branch and location), the
// records' lines, and files attached to records on the media disk.
//
// Header and line values are custom fields of the run-time entities
// `custom_form:<key>` and `custom_form_line:<key>` (ADR 011), so the
// definitions' entity column grows to hold them. Records keep the totals of
// their number and money line fields (`totals`, money as minor units with
// the currency) and the first money total as `amount_minor`/`amount_currency`
// for lists and the approvals inbox (ADR 003). Records are archived, never
// deleted (TEN-06). Users are referenced by (tenant_id, id).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table custom_field_definitions alter column entity type varchar(71)');
        DB::statement('alter table custom_field_definitions alter column lookup_target type varchar(71)');
        DB::statement('alter table custom_field_files alter column entity type varchar(71)');

        Schema::create('custom_form_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('key', 30);
            // Single-language tenant text, typed once.
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            // NUM-01: the numbered document type its records draw from.
            $table->string('number_type', 60);
            $table->boolean('workflow')->default(false);
            $table->boolean('has_lines')->default(false);
            // CF-05: the line table's columns in order (line custom field keys; empty: all, by position).
            $table->jsonb('line_fields')->default('[]');
            $table->boolean('attachments')->default(false);
            // RBAC-04: roles that may use the type (empty: everyone holding the core.custom_form permissions).
            $table->jsonb('role_ids')->default('[]');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            // A key is never reused, even once archived: numbers, flows and fields name it.
            $table->unique(['tenant_id', 'key']);
        });

        DB::statement("alter table custom_form_types add constraint custom_form_types_key_check check (key ~ '^[a-z][a-z0-9_]{0,29}$')");
        DB::statement("alter table custom_form_types add constraint custom_form_types_json_check check (jsonb_typeof(line_fields) = 'array' and jsonb_typeof(role_ids) = 'array')");
        Rls::enable('custom_form_types');

        Schema::create('custom_form_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('type_id')->constrained('custom_form_types')->restrictOnDelete();
            $table->string('number', 80)->nullable();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->jsonb('custom')->default('{}');
            $table->jsonb('totals')->default('{}');
            $table->bigInteger('amount_minor')->nullable();
            $table->char('amount_currency', 3)->nullable();
            $table->uuid('created_by');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign('amount_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['tenant_id', 'type_id', 'created_at']);
            $table->index('company_id');
        });

        DB::statement("alter table custom_form_records add constraint custom_form_records_status_check check (status in ('draft', 'pending', 'submitted', 'approved', 'rejected', 'cancelled'))");
        DB::statement("alter table custom_form_records add constraint custom_form_records_json_check check (jsonb_typeof(custom) = 'object' and jsonb_typeof(totals) = 'object')");
        DB::statement('alter table custom_form_records add constraint custom_form_records_amount_check check ((amount_minor is null) = (amount_currency is null))');
        DB::statement('create unique index custom_form_records_number_unique on custom_form_records (tenant_id, type_id, number) where number is not null');
        DB::statement('create index custom_form_records_custom_gin on custom_form_records using gin (custom jsonb_path_ops)');
        Rls::enable('custom_form_records');

        Schema::create('custom_form_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('record_id')->constrained('custom_form_records')->restrictOnDelete();
            $table->integer('position');
            $table->jsonb('custom')->default('{}');
            $table->timestampsTz();

            $table->unique(['record_id', 'position']);
        });

        DB::statement("alter table custom_form_lines add constraint custom_form_lines_custom_check check (jsonb_typeof(custom) = 'object')");
        Rls::enable('custom_form_lines');

        // CF-04: files attached to a record, uploaded first (record_id null,
        // usable by their uploader only), tied to the record when it is saved.
        Schema::create('custom_form_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('type_id')->constrained('custom_form_types')->restrictOnDelete();
            $table->foreignUuid('record_id')->nullable()->constrained('custom_form_records')->restrictOnDelete();
            $table->string('disk', 20);
            $table->string('path')->unique();
            $table->string('name');
            $table->string('mime', 100);
            $table->integer('size');
            $table->uuid('uploaded_by');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'uploaded_by'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'record_id']);
        });

        Rls::enable('custom_form_attachments');
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_form_attachments');
        Schema::dropIfExists('custom_form_lines');
        Schema::dropIfExists('custom_form_records');
        Schema::dropIfExists('custom_form_types');
    }
};
