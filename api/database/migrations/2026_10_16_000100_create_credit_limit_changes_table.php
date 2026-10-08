<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MD-01, WF-01, APR-01: a request to change a party's credit limit, run
// through the `core.credit_limit_change` flow and applied to the party
// when approved. `company_id` is the party's company, or for a shared
// party the company the requester asked for. The current limit is a
// snapshot taken when the request was made; amounts are minor units plus
// an ISO 4217 code (ADR 003). `seq` numbers requests per tenant
// (CLC-000123) until the numbering service (Phase 5) replaces it.
// Users are referenced by (tenant_id, id), so a row can never point at
// another tenant's user.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_limit_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->integer('seq');
            $table->string('number', 30);
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->money('current_limit', nullable: true);
            $table->money('requested_limit');
            $table->text('reason');
            $table->string('status', 20);
            $table->uuid('requested_by');
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            // Why an approved request was not applied: the party changed meanwhile (H1).
            $table->string('conflict_reason', 30)->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'requested_by'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'decided_by'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign('current_limit_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('requested_limit_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['tenant_id', 'seq']);
            $table->unique(['tenant_id', 'number']);
            $table->index('party_id');
            $table->index('company_id');
        });

        DB::statement("alter table credit_limit_changes add constraint credit_limit_changes_status_check check (status in ('draft', 'pending', 'approved', 'rejected', 'cancelled', 'applied', 'conflicted'))");
        DB::statement('alter table credit_limit_changes add constraint credit_limit_changes_current_check check ((current_limit_minor is null) = (current_limit_currency is null) and (current_limit_minor is null or current_limit_minor >= 0))');
        DB::statement('alter table credit_limit_changes add constraint credit_limit_changes_requested_check check (requested_limit_minor >= 0)');
        // One open request per party at a time.
        DB::statement("create unique index credit_limit_changes_one_pending on credit_limit_changes (party_id) where status in ('draft', 'pending')");

        Rls::enable('credit_limit_changes');
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_limit_changes');
    }
};
