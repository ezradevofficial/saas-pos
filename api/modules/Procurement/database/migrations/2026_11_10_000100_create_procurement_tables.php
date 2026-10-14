<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Procurement module (docs/modules/procurement.md). Every table is the
// tenant's, under forced row-level security (TEN-01). Money is bigint minor
// units next to its currency, base amounts in the company's base currency,
// rates numeric(18,8) through the fxSnapshot columns (ADR 003, CUR-04);
// quantities numeric(20,6). Users are referenced by (tenant_id, id), so a
// row never points at another tenant's user. Documents are never deleted
// (TEN-06): drafts end cancelled. Sealed bids (PRC-09) keep only ciphertext
// until their tender's opening; the tender's key is itself encrypted with
// the application key and never exported (EXP-02). The supplier portal
// (PRC-07) finds the tenant of a token hash through security-definer
// functions that return nothing but the tenant id (ADR 002).
return new class extends Migration
{
    public function up(): void
    {
        $userRef = function (Blueprint $table, string $column, bool $nullable = true): void {
            $definition = $table->uuid($column);

            if ($nullable) {
                $definition->nullable();
            }

            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        };

        $qty = fn (Blueprint $table, string $column) => $table->decimal($column, 20, 6);
        $currency = function (Blueprint $table, string $column, bool $nullable = false): void {
            $definition = $table->char($column, 3);

            if ($nullable) {
                $definition->nullable();
            }

            $table->foreign($column)->references('code')->on('currencies')->restrictOnDelete();
        };
        $dimensions = function (Blueprint $table): void {
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->restrictOnDelete();
        };
        $cancel = function (Blueprint $table) use ($userRef): void {
            $userRef($table, 'cancelled_by');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
        };
        $status = fn (string $table, array $statuses) => DB::statement(sprintf(
            "alter table %s add constraint %s_status_check check (status in ('%s'))", $table, $table, implode("', '", $statuses),
        ));

        // PRC-01: three-way match tolerances and the over-receipt allowance per company.
        Schema::create('procurement_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->decimal('price_tolerance_percent', 7, 4)->default(0);
            $table->decimal('qty_tolerance_percent', 7, 4)->default(0);
            $table->decimal('over_receipt_percent', 7, 4)->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id']);
        });
        DB::statement('alter table procurement_settings add constraint procurement_settings_range_check check (price_tolerance_percent between 0 and 100 and qty_tolerance_percent between 0 and 100 and over_receipt_percent between 0 and 100)');
        Rls::enable('procurement_settings');

        // PRC-02: purchase requisitions.
        Schema::create('procurement_requisitions', function (Blueprint $table) use ($userRef, $currency, $cancel) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80)->nullable();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('status', 12);
            $table->text('purpose')->nullable();
            $table->date('needed_by')->nullable();
            $currency($table, 'currency');
            $table->bigInteger('estimated_total_minor')->default(0);
            $table->jsonb('custom')->nullable();
            $userRef($table, 'requested_by', nullable: false);
            $table->timestampTz('submitted_at')->nullable();
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $cancel($table);
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'created_at']);
            $table->index(['tenant_id', 'branch_id']);
        });
        $status('procurement_requisitions', ['draft', 'pending', 'approved', 'rejected', 'cancelled', 'ordered']);
        DB::statement('create index procurement_requisitions_custom on procurement_requisitions using gin (custom jsonb_path_ops)');
        Rls::enable('procurement_requisitions');

        Schema::create('procurement_requisition_lines', function (Blueprint $table) use ($qty, $dimensions) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('requisition_id')->constrained('procurement_requisitions')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 500);
            $table->foreignUuid('uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $qty($table, 'qty');
            $table->bigInteger('estimated_unit_price_minor')->nullable();
            $dimensions($table);
            $table->date('needed_by')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'requisition_id']);
        });
        DB::statement('alter table procurement_requisition_lines add constraint procurement_requisition_lines_qty_check check (qty > 0)');
        Rls::enable('procurement_requisition_lines');

        // PRC-03: requests for quotation.
        Schema::create('procurement_rfqs', function (Blueprint $table) use ($userRef, $cancel) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80)->nullable();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('requisition_id')->nullable()->constrained('procurement_requisitions')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('note')->nullable();
            $table->timestampTz('deadline_at')->nullable();
            $table->string('status', 12);
            $userRef($table, 'created_by', nullable: false);
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $cancel($table);
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'created_at']);
            $table->index(['tenant_id', 'branch_id']);
        });
        $status('procurement_rfqs', ['draft', 'issued', 'closed', 'cancelled']);
        Rls::enable('procurement_rfqs');

        Schema::create('procurement_rfq_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('rfq_id')->constrained('procurement_rfqs')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 500);
            $table->foreignUuid('uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $qty($table, 'qty');
            $table->foreignUuid('requisition_line_id')->nullable()->constrained('procurement_requisition_lines')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'rfq_id']);
        });
        DB::statement('alter table procurement_rfq_lines add constraint procurement_rfq_lines_qty_check check (qty > 0)');
        Rls::enable('procurement_rfq_lines');

        Schema::create('procurement_rfq_suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('rfq_id')->constrained('procurement_rfqs')->restrictOnDelete();
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'rfq_id', 'party_id']);
        });
        Rls::enable('procurement_rfq_suppliers');

        // PRC-04: supplier quotes, typed by the buyer.
        Schema::create('procurement_quotes', function (Blueprint $table) use ($userRef, $currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('rfq_id')->constrained('procurement_rfqs')->restrictOnDelete();
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('reference', 100)->nullable();
            $table->date('quote_date');
            $table->date('valid_until')->nullable();
            $currency($table, 'currency');
            $table->fxSnapshot('fx');
            $currency($table, 'base_currency');
            $table->string('status', 12);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('base_total_minor')->default(0);
            $table->text('note')->nullable();
            $userRef($table, 'created_by', nullable: false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'rfq_id', 'party_id']);
        });
        $status('procurement_quotes', ['received', 'awarded', 'not_awarded']);
        Rls::enable('procurement_quotes');

        Schema::create('procurement_quote_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('quote_id')->constrained('procurement_quotes')->restrictOnDelete();
            $table->foreignUuid('rfq_line_id')->constrained('procurement_rfq_lines')->restrictOnDelete();
            $qty($table, 'qty');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('base_unit_price_minor');
            $table->integer('lead_time_days')->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('selected')->default(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'quote_id', 'rfq_line_id']);
        });
        DB::statement('alter table procurement_quote_lines add constraint procurement_quote_lines_price_check check (unit_price_minor >= 0 and qty > 0)');
        Rls::enable('procurement_quote_lines');

        // PRC-06: tenders. `seal_secret` is the tender's own key (PRC-09),
        // encrypted with the application key: never exported, never shown.
        Schema::create('procurement_tenders', function (Blueprint $table) use ($userRef, $currency, $cancel) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80)->nullable();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('requisition_id')->nullable()->constrained('procurement_requisitions')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('kind', 10);
            $currency($table, 'currency');
            $table->timestampTz('deadline_at');
            $table->timestampTz('opening_at');
            $table->integer('technical_weight');
            $table->integer('financial_weight');
            $table->decimal('pass_mark', 7, 4)->default(0);
            $table->string('status', 12);
            $table->text('seal_secret');
            $table->char('public_token_hash', 64)->nullable()->unique();
            $userRef($table, 'created_by', nullable: false);
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('evaluated_at')->nullable();
            $table->timestampTz('awarded_at')->nullable();
            $userRef($table, 'awarded_by');
            $table->uuid('awarded_bid_id')->nullable();
            $table->text('award_reason')->nullable();
            $cancel($table);
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'created_at']);
            $table->index(['tenant_id', 'branch_id']);
        });
        $status('procurement_tenders', ['draft', 'published', 'opened', 'evaluated', 'awarded', 'cancelled']);
        DB::statement("alter table procurement_tenders add constraint procurement_tenders_rules_check check (kind in ('open', 'invited') and opening_at >= deadline_at and technical_weight between 0 and 100 and financial_weight between 0 and 100 and technical_weight + financial_weight = 100 and pass_mark between 0 and 100)");
        Rls::enable('procurement_tenders');

        Schema::create('procurement_tender_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('tender_id')->constrained('procurement_tenders')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 500);
            $table->foreignUuid('uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $qty($table, 'qty');
            $table->timestampsTz();

            $table->index(['tenant_id', 'tender_id']);
        });
        DB::statement('alter table procurement_tender_lines add constraint procurement_tender_lines_qty_check check (qty > 0)');
        Rls::enable('procurement_tender_lines');

        Schema::create('procurement_tender_criteria', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('tender_id')->constrained('procurement_tenders')->restrictOnDelete();
            $table->integer('line_no');
            $table->string('name', 200);
            $table->integer('weight');
            $table->integer('max_score');
            $table->timestampsTz();

            $table->index(['tenant_id', 'tender_id']);
        });
        DB::statement('alter table procurement_tender_criteria add constraint procurement_tender_criteria_check check (weight between 1 and 100 and max_score between 1 and 1000)');
        Rls::enable('procurement_tender_criteria');

        // PRC-06, PRC-10, PRC-11: the committee (openers and evaluators).
        Schema::create('procurement_tender_members', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('tender_id')->constrained('procurement_tenders')->restrictOnDelete();
            $userRef($table, 'user_id', nullable: false);
            $table->string('role', 10);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'tender_id', 'user_id', 'role']);
        });
        DB::statement("alter table procurement_tender_members add constraint procurement_tender_members_role_check check (role in ('opener', 'evaluator'))");
        Rls::enable('procurement_tender_members');

        // PRC-07, PRC-08: who may bid; the link token is stored as its sha256.
        Schema::create('procurement_tender_bidders', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('tender_id')->constrained('procurement_tenders')->restrictOnDelete();
            $table->foreignUuid('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('name', 200);
            $table->string('tax_id', 60)->nullable();
            $table->string('email', 254);
            $table->string('source', 12);
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestampTz('token_expires_at')->nullable();
            $table->timestampTz('link_sent_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $userRef($table, 'invited_by');
            $table->timestampTz('removed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'tender_id']);
        });
        DB::statement("alter table procurement_tender_bidders add constraint procurement_tender_bidders_source_check check (source in ('invited', 'registered'))");
        DB::statement('create unique index procurement_tender_bidders_one_email on procurement_tender_bidders (tenant_id, tender_id, lower(email)) where removed_at is null');
        Rls::enable('procurement_tender_bidders');

        // PRC-07: a bidder's short portal session, bound to its tender.
        Schema::create('procurement_portal_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('bidder_id')->constrained('procurement_tender_bidders')->restrictOnDelete();
            $table->foreignUuid('tender_id')->constrained('procurement_tenders')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'bidder_id']);
        });
        Rls::enable('procurement_portal_sessions');

        // PRC-09: sealed bids. Before opening only `sealed_payload` (ciphertext)
        // and its `fingerprint` hold the content; the plain columns stay null.
        Schema::create('procurement_bids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('tender_id')->constrained('procurement_tenders')->restrictOnDelete();
            $table->foreignUuid('bidder_id')->constrained('procurement_tender_bidders')->restrictOnDelete();
            $table->string('status', 12);
            $table->integer('version')->default(1);
            $table->timestampTz('submitted_at', 6);
            $table->timestampTz('withdrawn_at')->nullable();
            $table->char('fingerprint', 64);
            $table->text('sealed_payload');
            // Plain after opening only (PRC-10).
            $table->timestampTz('opened_at')->nullable();
            $table->char('currency', 3)->nullable();
            $table->fxSnapshot('fx');
            $table->bigInteger('total_minor')->nullable();
            $table->bigInteger('base_total_minor')->nullable();
            $table->jsonb('lines')->nullable();
            $table->text('technical_response')->nullable();
            $table->boolean('complete')->nullable();
            // PRC-11: scores in basis points (0..10000), the rank and the outcome.
            $table->integer('technical_score_bp')->nullable();
            $table->integer('financial_score_bp')->nullable();
            $table->integer('combined_score_bp')->nullable();
            $table->integer('rank')->nullable();
            $table->string('outcome', 12)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'tender_id', 'bidder_id']);
        });
        $status('procurement_bids', ['submitted', 'withdrawn', 'opened', 'tampered']);
        DB::statement("alter table procurement_bids add constraint procurement_bids_outcome_check check (outcome is null or outcome in ('awarded', 'regretted', 'disqualified'))");
        DB::statement("alter table procurement_bids add constraint procurement_bids_sealed_check check (status = 'opened' or (lines is null and total_minor is null and technical_response is null))");
        Rls::enable('procurement_bids');

        Schema::create('procurement_bid_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('tender_id')->constrained('procurement_tenders')->restrictOnDelete();
            $table->foreignUuid('bidder_id')->constrained('procurement_tender_bidders')->restrictOnDelete();
            $table->foreignUuid('bid_id')->nullable()->constrained('procurement_bids')->restrictOnDelete();
            $table->string('disk', 40);
            $table->string('path', 300);
            $table->string('name', 255);
            $table->string('mime', 120);
            $table->bigInteger('size');
            $table->timestampTz('removed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'bidder_id']);
        });
        Rls::enable('procurement_bid_files');

        // PRC-10: each opener's confirmation.
        Schema::create('procurement_tender_openings', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('tender_id')->constrained('procurement_tenders')->restrictOnDelete();
            $userRef($table, 'user_id', nullable: false);
            $table->timestampTz('confirmed_at', 6);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'tender_id', 'user_id']);
        });
        Rls::enable('procurement_tender_openings');

        // PRC-11: technical scores per bid, criterion and evaluator.
        Schema::create('procurement_bid_scores', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('bid_id')->constrained('procurement_bids')->restrictOnDelete();
            $table->foreignUuid('criterion_id')->constrained('procurement_tender_criteria')->restrictOnDelete();
            $userRef($table, 'evaluator_id', nullable: false);
            $table->decimal('score', 10, 4);
            $table->string('comment', 1000)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'bid_id', 'criterion_id', 'evaluator_id']);
        });
        DB::statement('alter table procurement_bid_scores add constraint procurement_bid_scores_score_check check (score >= 0)');
        Rls::enable('procurement_bid_scores');

        // PRC-13: purchase orders.
        Schema::create('procurement_orders', function (Blueprint $table) use ($userRef, $currency, $cancel) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80)->nullable();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $currency($table, 'currency');
            $table->fxSnapshot('fx');
            $currency($table, 'base_currency', nullable: true);
            $table->boolean('tax_inclusive')->default(false);
            $table->date('order_date');
            $table->date('expected_on')->nullable();
            $table->text('terms')->nullable();
            $table->string('status', 20);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('base_subtotal_minor')->nullable();
            $table->bigInteger('base_tax_minor')->nullable();
            $table->bigInteger('base_total_minor')->nullable();
            $table->foreignUuid('requisition_id')->nullable()->constrained('procurement_requisitions')->restrictOnDelete();
            $table->foreignUuid('quote_id')->nullable()->constrained('procurement_quotes')->restrictOnDelete();
            $table->foreignUuid('bid_id')->nullable()->constrained('procurement_bids')->restrictOnDelete();
            $table->jsonb('custom')->nullable();
            $userRef($table, 'requested_by', nullable: false);
            $table->timestampTz('submitted_at')->nullable();
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $cancel($table);
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'created_at']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'party_id']);
        });
        $status('procurement_orders', ['draft', 'pending', 'approved', 'partially_received', 'received', 'closed', 'rejected', 'cancelled']);
        DB::statement('create index procurement_orders_custom on procurement_orders using gin (custom jsonb_path_ops)');
        Rls::enable('procurement_orders');

        Schema::create('procurement_order_lines', function (Blueprint $table) use ($qty, $dimensions) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('order_id')->constrained('procurement_orders')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 500);
            $table->foreignUuid('uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $qty($table, 'qty');
            $table->bigInteger('unit_price_minor');
            $table->foreignUuid('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_rate', 9, 4)->nullable();
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $qty($table, 'received_qty')->default(0);
            $qty($table, 'rejected_qty')->default(0);
            $qty($table, 'invoiced_qty')->default(0);
            $dimensions($table);
            $table->foreignUuid('requisition_line_id')->nullable()->constrained('procurement_requisition_lines')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
        });
        DB::statement('alter table procurement_order_lines add constraint procurement_order_lines_check check (qty > 0 and unit_price_minor >= 0 and received_qty >= 0 and rejected_qty >= 0 and invoiced_qty >= 0)');
        Rls::enable('procurement_order_lines');

        // PRC-14: goods received notes.
        Schema::create('procurement_receipts', function (Blueprint $table) use ($userRef, $currency, $cancel) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80)->nullable();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained('procurement_orders')->restrictOnDelete();
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->timestampTz('received_at');
            $table->string('delivery_note', 100)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 12);
            $currency($table, 'currency');
            $table->fxSnapshot('fx');
            $currency($table, 'base_currency', nullable: true);
            $table->bigInteger('value_minor')->default(0);
            $table->bigInteger('base_value_minor')->nullable();
            $table->bigInteger('landed_minor')->default(0);
            $userRef($table, 'created_by', nullable: false);
            $userRef($table, 'posted_by');
            $table->timestampTz('posted_at')->nullable();
            $cancel($table);
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'created_at']);
            $table->index(['tenant_id', 'location_id']);
            $table->index(['tenant_id', 'order_id']);
        });
        $status('procurement_receipts', ['draft', 'posted', 'cancelled']);
        Rls::enable('procurement_receipts');

        Schema::create('procurement_receipt_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('receipt_id')->constrained('procurement_receipts')->restrictOnDelete();
            $table->foreignUuid('order_line_id')->constrained('procurement_order_lines')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $qty($table, 'received_qty');
            $qty($table, 'rejected_qty')->default(0);
            $qty($table, 'accepted_qty');
            $table->string('reject_reason', 500)->nullable();
            $table->string('batch_number', 60)->nullable();
            $table->date('expires_on')->nullable();
            $table->jsonb('serials')->default('[]');
            $table->decimal('weight', 20, 6)->nullable();
            $table->bigInteger('value_minor')->default(0);
            $table->bigInteger('base_value_minor')->nullable();
            $table->bigInteger('landed_minor')->default(0);
            $table->boolean('on_time')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'receipt_id']);
            $table->index(['tenant_id', 'order_line_id']);
        });
        DB::statement('alter table procurement_receipt_lines add constraint procurement_receipt_lines_check check (received_qty > 0 and rejected_qty >= 0 and rejected_qty <= received_qty and accepted_qty = received_qty - rejected_qty and (weight is null or weight >= 0))');
        Rls::enable('procurement_receipt_lines');

        // PRC-15: supplier invoices and their three-way match.
        Schema::create('procurement_invoices', function (Blueprint $table) use ($userRef, $currency, $cancel) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80)->nullable();
            $table->string('supplier_reference', 100);
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained('procurement_orders')->restrictOnDelete();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $currency($table, 'currency');
            $table->fxSnapshot('fx');
            $currency($table, 'base_currency', nullable: true);
            $table->boolean('tax_inclusive')->default(false);
            $table->string('status', 12);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('base_subtotal_minor')->nullable();
            $table->bigInteger('base_tax_minor')->nullable();
            $table->bigInteger('base_total_minor')->nullable();
            $table->bigInteger('base_grni_minor')->nullable();
            $table->text('override_reason')->nullable();
            $userRef($table, 'created_by', nullable: false);
            $userRef($table, 'approved_by');
            $table->timestampTz('approved_at')->nullable();
            $cancel($table);
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'created_at']);
            $table->index(['tenant_id', 'order_id']);
        });
        $status('procurement_invoices', ['draft', 'matched', 'exception', 'approved', 'cancelled']);
        DB::statement("create unique index procurement_invoices_one_reference on procurement_invoices (tenant_id, party_id, lower(supplier_reference)) where status <> 'cancelled'");
        Rls::enable('procurement_invoices');

        Schema::create('procurement_invoice_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('invoice_id')->constrained('procurement_invoices')->restrictOnDelete();
            $table->foreignUuid('order_line_id')->constrained('procurement_order_lines')->restrictOnDelete();
            $table->integer('line_no');
            $qty($table, 'qty');
            $table->bigInteger('unit_price_minor');
            $table->foreignUuid('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_rate', 9, 4)->nullable();
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->string('match_status', 24)->nullable();
            $table->bigInteger('price_variance_minor')->default(0);
            $qty($table, 'qty_variance')->default(0);
            $table->bigInteger('base_net_minor')->nullable();
            $table->bigInteger('base_grni_minor')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'order_line_id']);
        });
        DB::statement("alter table procurement_invoice_lines add constraint procurement_invoice_lines_check check (qty > 0 and unit_price_minor >= 0 and (match_status is null or match_status in ('matched', 'price_variance', 'qty_exceeds_received', 'not_received')))");
        Rls::enable('procurement_invoice_lines');

        // PRC-17: landed costs.
        Schema::create('procurement_landed_costs', function (Blueprint $table) use ($userRef, $currency, $cancel) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80)->nullable();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->date('cost_date');
            $table->string('method', 10);
            $table->string('description', 200)->nullable();
            $table->string('status', 12);
            $currency($table, 'base_currency', nullable: true);
            $table->bigInteger('base_total_minor')->default(0);
            $userRef($table, 'created_by', nullable: false);
            $userRef($table, 'posted_by');
            $table->timestampTz('posted_at')->nullable();
            $cancel($table);
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'created_at']);
        });
        $status('procurement_landed_costs', ['draft', 'posted', 'cancelled']);
        DB::statement("alter table procurement_landed_costs add constraint procurement_landed_costs_method_check check (method in ('value', 'quantity', 'weight'))");
        Rls::enable('procurement_landed_costs');

        Schema::create('procurement_landed_cost_charges', function (Blueprint $table) use ($currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('landed_cost_id')->constrained('procurement_landed_costs')->restrictOnDelete();
            $table->integer('line_no');
            $table->string('description', 200);
            $table->foreignUuid('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $currency($table, 'currency');
            $table->bigInteger('amount_minor');
            $table->fxSnapshot('fx');
            $table->bigInteger('base_amount_minor')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'landed_cost_id']);
        });
        DB::statement('alter table procurement_landed_cost_charges add constraint procurement_landed_cost_charges_amount_check check (amount_minor > 0)');
        Rls::enable('procurement_landed_cost_charges');

        Schema::create('procurement_landed_cost_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('landed_cost_id')->constrained('procurement_landed_costs')->restrictOnDelete();
            $table->foreignUuid('receipt_id')->constrained('procurement_receipts')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'landed_cost_id', 'receipt_id']);
        });
        Rls::enable('procurement_landed_cost_receipts');

        Schema::create('procurement_landed_cost_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('landed_cost_id')->constrained('procurement_landed_costs')->restrictOnDelete();
            $table->foreignUuid('receipt_id')->constrained('procurement_receipts')->restrictOnDelete();
            $table->foreignUuid('receipt_line_id')->constrained('procurement_receipt_lines')->restrictOnDelete();
            $table->decimal('basis', 26, 6);
            $table->bigInteger('base_amount_minor');
            // Whether the receipt was already posted when this was allocated (LandedCostAllocated).
            $table->boolean('after_receipt');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'landed_cost_id', 'receipt_line_id']);
        });
        Rls::enable('procurement_landed_cost_allocations');

        // PRC-07: the tenant of a portal link or session token hash, across
        // tenants (the portal knows no tenant before it reads the token).
        // Runs as the schema owner and returns nothing but the id.
        DB::unprepared(<<<'SQL'
            create or replace function public.procurement_portal_tenant(p_kind text, p_token_hash text) returns uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select case p_kind
                    when 'link' then (select b.tenant_id from public.procurement_tender_bidders b where b.token_hash = p_token_hash limit 1)
                    when 'session' then (select s.tenant_id from public.procurement_portal_sessions s where s.token_hash = p_token_hash limit 1)
                    when 'notice' then (select t.tenant_id from public.procurement_tenders t where t.public_token_hash = p_token_hash limit 1)
                end
            $$;

            revoke all on function public.procurement_portal_tenant(text, text) from public;
            SQL);

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("grant execute on function public.procurement_portal_tenant(text, text) to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.procurement_portal_tenant(text, text)');

        foreach ([
            'procurement_landed_cost_allocations', 'procurement_landed_cost_receipts', 'procurement_landed_cost_charges', 'procurement_landed_costs',
            'procurement_invoice_lines', 'procurement_invoices', 'procurement_receipt_lines', 'procurement_receipts',
            'procurement_order_lines', 'procurement_orders', 'procurement_bid_scores', 'procurement_tender_openings',
            'procurement_bid_files', 'procurement_bids', 'procurement_portal_sessions', 'procurement_tender_bidders',
            'procurement_tender_members', 'procurement_tender_criteria', 'procurement_tender_lines', 'procurement_tenders',
            'procurement_quote_lines', 'procurement_quotes', 'procurement_rfq_suppliers', 'procurement_rfq_lines', 'procurement_rfqs',
            'procurement_requisition_lines', 'procurement_requisitions', 'procurement_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
