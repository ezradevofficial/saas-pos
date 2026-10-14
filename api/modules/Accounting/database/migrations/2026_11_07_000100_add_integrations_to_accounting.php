<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Stage 7B, Accounting integrations (docs/modules/accounting.md, ACC-24 to
// ACC-30): how a company posts POS sales (one journal per sale, or a daily
// summary per location), where a journal's source opens in its own module
// (drill-down), and the sales waiting for their daily summary. The new
// table is the tenant's, under forced row-level security (TEN-01).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::table('acc_settings', function (Blueprint $table) {
            $table->string('sales_posting', 15)->default('per_sale');
        });
        DB::statement("alter table acc_settings add constraint acc_settings_sales_posting_check check (sales_posting in ('per_sale', 'daily_summary'))");

        // ACC-17: the record a system journal came from, as its module names it ({kind, id, ...}).
        Schema::table('acc_journals', function (Blueprint $table) {
            $table->jsonb('source_link')->nullable();
        });

        // ACC-26: sales kept for their location's daily summary journal.
        Schema::create('acc_source_queue', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 60);
            $table->uuid('source_id');
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->date('local_date');
            $table->char('currency', 3);
            $table->jsonb('payload');
            $table->uuid('summary_source_id')->nullable();
            $table->foreignUuid('journal_id')->nullable()->constrained('acc_journals')->restrictOnDelete();
            $table->timestampTz('summarised_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'source_type', 'source_id']);
            $table->index(['company_id', 'summarised_at', 'local_date']);
        });
        DB::statement('alter table acc_source_queue add constraint acc_source_queue_summarised_check check ((summarised_at is null) = (journal_id is null))');
        Rls::enable('acc_source_queue');
    }

    public function down(): void
    {
        Schema::drop('acc_source_queue');
        Schema::table('acc_journals', fn (Blueprint $table) => $table->dropColumn('source_link'));
        DB::statement('alter table acc_settings drop constraint acc_settings_sales_posting_check');
        Schema::table('acc_settings', fn (Blueprint $table) => $table->dropColumn('sales_posting'));
    }
};
