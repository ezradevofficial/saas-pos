<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stage 7C closing review (docs/modules/payroll.md, decisions 29 to 40):
// Payroll's copy of HR is refreshed incrementally, one `updated_at` cursor
// per company and source; a contract copy keeps the version it was copied
// from, so a late ContractChanged never overwrites a newer one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_sync_cursors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('source', 20);
            $table->timestampTz('cursor', 6);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id', 'source']);
        });
        Rls::enable('payroll_sync_cursors');

        Schema::table('payroll_contracts', function (Blueprint $table) {
            $table->unsignedInteger('source_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_contracts', function (Blueprint $table) {
            $table->dropColumn('source_version');
        });

        Schema::dropIfExists('payroll_sync_cursors');
    }
};
