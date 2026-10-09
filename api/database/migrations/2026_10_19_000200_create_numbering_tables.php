<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// NUM-01: number formats per document type, for the whole tenant, a
// company or a branch (the most specific applies), and their counters per
// period ('all' when the format never resets, else the year). Counters
// advance with UPDATE ... RETURNING under the row lock, so two documents
// never share a number; NUM-02 device ranges reserve blocks from the same
// counter.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_formats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('document_type', 60);
            $table->foreignUuid('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('pattern', 60);
            $table->string('reset', 10);
            $table->boolean('gapless')->default(false);
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('branch_id');
        });

        DB::statement("alter table number_formats add constraint number_formats_reset_check check (reset in ('yearly', 'never'))");
        DB::statement('alter table number_formats add constraint number_formats_scope_check check (branch_id is null or company_id is not null)');
        DB::statement('create unique index number_formats_tenant_unique on number_formats (tenant_id, document_type) where company_id is null');
        DB::statement('create unique index number_formats_company_unique on number_formats (company_id, document_type) where company_id is not null and branch_id is null');
        DB::statement('create unique index number_formats_branch_unique on number_formats (branch_id, document_type) where branch_id is not null');
        Rls::enable('number_formats');

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('number_format_id')->constrained()->restrictOnDelete();
            $table->string('period', 4);
            $table->bigInteger('next_value')->default(1);
            $table->timestampsTz();

            $table->unique(['number_format_id', 'period']);
        });

        DB::statement("alter table number_sequences add constraint number_sequences_period_check check (period = 'all' or period ~ '^[0-9]{4}$')");
        DB::statement('alter table number_sequences add constraint number_sequences_next_check check (next_value >= 1)');
        Rls::enable('number_sequences');
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('number_formats');
    }
};
