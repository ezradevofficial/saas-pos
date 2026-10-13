<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// IMP-01..IMP-03: imports. One row per uploaded file (its importer, mode,
// column mapping, status and counts), the records each import created or
// changed with their before images (undo, IMP-02), and the mapping last
// used per importer. Files live on the media disk under the tenant.
// Never deleted; an undone import keeps its rows (TEN-06).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('user_id');
            $table->string('importer', 40);
            $table->string('mode', 10)->default('create');
            $table->string('status', 20);
            $table->string('locale', 5);
            $table->string('file_disk', 20);
            $table->string('file_path')->unique();
            $table->string('file_name');
            $table->string('file_format', 4);
            $table->integer('file_size');
            $table->jsonb('headers')->default('[]');
            $table->jsonb('mapping')->default('{}');
            $table->integer('total_rows')->default(0);
            // IMP-03: progress of the dry run or the import (whichever ran last).
            $table->integer('processed_rows')->default(0);
            $table->integer('created_rows')->default(0);
            $table->integer('updated_rows')->default(0);
            $table->integer('failed_rows')->default(0);
            $table->boolean('checked')->default(false);
            $table->string('error_file_path')->nullable();
            $table->string('result_file_path')->nullable();
            $table->string('failure', 500)->nullable();
            // IMP-02: the rows that blocked the last undo.
            $table->jsonb('blocking')->default('[]');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('undone_at')->nullable();
            $table->uuid('undone_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'undone_by'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });

        DB::statement("alter table imports add constraint imports_status_check check (status in ('ready', 'checking', 'checked', 'importing', 'completed', 'failed', 'undoing', 'undone'))");
        DB::statement("alter table imports add constraint imports_mode_check check (mode in ('create', 'upsert'))");
        DB::statement("alter table imports add constraint imports_format_check check (file_format in ('csv', 'xlsx'))");
        DB::statement("alter table imports add constraint imports_json_check check (jsonb_typeof(headers) = 'array' and jsonb_typeof(mapping) = 'object' and jsonb_typeof(blocking) = 'array')");
        // One import running per tenant and importer (a dry run, an import or an undo).
        DB::statement("create unique index imports_one_running on imports (tenant_id, importer) where status in ('checking', 'importing', 'undoing')");
        Rls::enable('imports');

        Schema::create('import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('import_id')->constrained('imports')->restrictOnDelete();
            $table->integer('row_number');
            $table->string('record_type', 40);
            $table->uuid('record_id');
            $table->string('action', 10);
            // The record's values before the import changed it (null when created).
            $table->jsonb('before')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('import_id');
            $table->index(['tenant_id', 'record_type', 'record_id']);
        });

        DB::statement("alter table import_rows add constraint import_rows_action_check check (action in ('created', 'updated'))");
        Rls::enable('import_rows');

        Schema::create('import_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('importer', 40);
            // Column key => the header text it was mapped from last time.
            $table->jsonb('mapping')->default('{}');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'importer']);
        });

        DB::statement("alter table import_mappings add constraint import_mappings_json_check check (jsonb_typeof(mapping) = 'object')");
        Rls::enable('import_mappings');
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mappings');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('imports');
    }
};
