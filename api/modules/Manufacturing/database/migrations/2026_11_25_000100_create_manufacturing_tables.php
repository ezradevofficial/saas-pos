<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Manufacturing module (docs/modules/manufacturing.md). Every table is the
// tenant's, under forced row-level security (TEN-01). Quantities are exact
// decimals, numeric(20,6), as entered and in the item's base unit;
// percentages numeric(7,4); money is bigint minor units with its currency
// (ADR 003), known only once Inventory valued the movement (MFG-15, MFG-16).
// Users are referenced by (tenant_id, id). Business records are never
// deleted (TEN-06): BOMs are archived, orders end closed, cancelled or
// rejected; published BOM versions are immutable (MFG-03).
//
// MFG-15: the Inventory movement kinds Manufacturing's documents post are
// rows of Inventory's lookup `inventory_movement_kinds`, inserted here and
// (on rollback) deleted here, as Stores does for its kinds.
return new class extends Migration
{
    public const KINDS = ['production_issue', 'production_return', 'production_receipt', 'recipe_consumption', 'recipe_return'];

    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        $userRef = function (Blueprint $table, string $column, bool $nullable = true): void {
            $definition = $table->uuid($column);

            if ($nullable) {
                $definition->nullable();
            }

            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        };

        $qty = fn (Blueprint $table, string $column) => $table->decimal($column, 20, 6);
        $percent = fn (Blueprint $table, string $column) => $table->decimal($column, 7, 4);
        $currency = function (Blueprint $table, bool $nullable = true): void {
            $definition = $table->char('currency', 3);

            if ($nullable) {
                $definition->nullable();
            }

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        };

        // MFG-29: a company's labour and overhead rates (per labour hour) and release approval.
        Schema::create('mfg_settings', function (Blueprint $table) use ($currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->bigInteger('labour_rate_minor')->default(0);
            $table->bigInteger('overhead_rate_minor')->default(0);
            $currency($table, nullable: false);
            $table->boolean('release_approval')->default(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id']);
        });
        DB::statement('alter table mfg_settings add constraint mfg_settings_check check (labour_rate_minor >= 0 and overhead_rate_minor >= 0)');
        Rls::enable('mfg_settings');

        // MFG-02: a bill of materials (production) or recipe (made to order) of a company's item.
        Schema::create('mfg_boms', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('name', 160)->nullable();
            // MFG-22, CF-06: custom field values.
            $table->jsonb('custom')->nullable();
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'item_id']);
        });
        DB::statement("alter table mfg_boms add constraint mfg_boms_check check (kind in ('production', 'recipe'))");
        DB::statement('create unique index mfg_boms_one_active on mfg_boms (tenant_id, company_id, item_id, kind) where archived_at is null');
        DB::statement('create index mfg_boms_custom on mfg_boms using gin (custom jsonb_path_ops)');
        Rls::enable('mfg_boms');

        // MFG-03, MFG-04: numbered versions; published ones are fixed from their date.
        Schema::create('mfg_bom_versions', function (Blueprint $table) use ($qty, $userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('bom_id')->constrained('mfg_boms')->restrictOnDelete();
            $table->integer('version_no');
            $table->string('status', 12);
            $table->timestampTz('effective_from', 6)->nullable();
            $qty($table, 'output_qty');
            $table->foreignUuid('output_uom_id')->constrained('uoms')->restrictOnDelete();
            $qty($table, 'output_qty_base');
            $table->integer('labour_minutes')->default(0);
            $table->text('note')->nullable();
            $userRef($table, 'created_by');
            $userRef($table, 'published_by');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'bom_id', 'version_no']);
            $table->index(['tenant_id', 'bom_id', 'effective_from']);
        });
        DB::statement("alter table mfg_bom_versions add constraint mfg_bom_versions_check check (status in ('draft', 'published') and output_qty > 0 and output_qty_base > 0 and labour_minutes >= 0 and (status = 'draft' or effective_from is not null))");
        DB::statement("create unique index mfg_bom_versions_one_draft on mfg_bom_versions (tenant_id, bom_id) where status = 'draft'");
        Rls::enable('mfg_bom_versions');

        Schema::create('mfg_bom_lines', function (Blueprint $table) use ($qty, $percent) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('bom_version_id')->constrained('mfg_bom_versions')->restrictOnDelete();
            $table->integer('line_no');
            $table->string('kind', 12);
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $qty($table, 'qty');
            $qty($table, 'qty_base');
            $percent($table, 'yield_percent')->default(100);
            $percent($table, 'wastage_percent')->default(0);
            $table->boolean('explode')->default(false);
            $percent($table, 'cost_share_percent')->default(0);
            $table->text('note')->nullable();
            // MFG-23: the import that added the line (undone only while its version is a draft).
            $table->uuid('import_id')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'bom_version_id']);
            $table->index(['tenant_id', 'item_id']);
        });
        DB::statement("alter table mfg_bom_lines add constraint mfg_bom_lines_check check (kind in ('component', 'by_product') and qty > 0 and qty_base > 0 and yield_percent > 0 and yield_percent <= 100 and wastage_percent >= 0 and wastage_percent <= 500 and cost_share_percent >= 0 and cost_share_percent < 100)");
        Rls::enable('mfg_bom_lines');

        // MFG-10 to MFG-16: production orders.
        Schema::create('mfg_orders', function (Blueprint $table) use ($qty, $userRef, $currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('bom_id')->constrained('mfg_boms')->restrictOnDelete();
            $table->foreignUuid('bom_version_id')->constrained('mfg_bom_versions')->restrictOnDelete();
            $qty($table, 'qty');
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $qty($table, 'qty_base');
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->string('status', 20);
            // MFG-11, MFG-17: whose ledger moves its stock, fixed at release.
            $table->string('ledger', 10)->nullable();
            $table->bigInteger('labour_rate_minor')->default(0);
            $table->bigInteger('overhead_rate_minor')->default(0);
            $currency($table);
            $qty($table, 'qty_good_base')->default(0);
            $qty($table, 'qty_scrap_base')->default(0);
            // MFG-16: actual cost (minor units of `currency`).
            $table->bigInteger('material_cost_minor')->default(0);
            $table->bigInteger('material_relieved_minor')->default(0);
            $table->bigInteger('labour_cost_minor')->default(0);
            $table->bigInteger('overhead_cost_minor')->default(0);
            $table->bigInteger('relieved_minor')->default(0);
            $table->bigInteger('variance_minor')->nullable();
            $table->text('note')->nullable();
            $table->jsonb('custom')->nullable();
            $userRef($table, 'planned_by', nullable: false);
            $userRef($table, 'released_by');
            $table->timestampTz('released_at')->nullable();
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $userRef($table, 'closed_by');
            $table->timestampTz('closed_at', 6)->nullable();
            $table->text('close_note')->nullable();
            $userRef($table, 'cancelled_by');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id', 'created_at']);
            $table->index(['tenant_id', 'item_id']);
        });
        DB::statement("alter table mfg_orders add constraint mfg_orders_check check (status in ('planned', 'pending_approval', 'released', 'in_progress', 'closed', 'cancelled', 'rejected') and (ledger is null or ledger in ('inventory', 'none')) and qty > 0 and qty_base > 0 and qty_good_base >= 0 and qty_scrap_base >= 0)");
        DB::statement('create index mfg_orders_custom on mfg_orders using gin (custom jsonb_path_ops)');
        Rls::enable('mfg_orders');

        // MFG-10, MFG-18: the order's materials (its version exploded for the planned quantity).
        Schema::create('mfg_order_materials', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('order_id')->constrained('mfg_orders')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $qty($table, 'qty_required_base');
            $qty($table, 'expected_loss_base')->default(0);
            $qty($table, 'qty_issued_base')->default(0);
            $qty($table, 'qty_returned_base')->default(0);
            $table->bigInteger('value_issued_minor')->default(0);
            $table->bigInteger('value_returned_minor')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
            $table->unique(['tenant_id', 'order_id', 'item_id']);
        });
        DB::statement('alter table mfg_order_materials add constraint mfg_order_materials_check check (qty_required_base >= 0 and qty_issued_base >= 0 and qty_returned_base >= 0 and qty_returned_base <= qty_issued_base)');
        Rls::enable('mfg_order_materials');

        // MFG-10, MFG-13: what the order is to give: the product and its by-products.
        Schema::create('mfg_order_outputs', function (Blueprint $table) use ($qty, $percent) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('order_id')->constrained('mfg_orders')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12);
            $qty($table, 'qty_planned_base');
            $qty($table, 'qty_reported_base')->default(0);
            $percent($table, 'cost_share_percent')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
        });
        DB::statement("alter table mfg_order_outputs add constraint mfg_order_outputs_check check (kind in ('product', 'by_product') and qty_planned_base >= 0 and qty_reported_base >= 0)");
        Rls::enable('mfg_order_outputs');

        // MFG-12: materials issued to an order.
        Schema::create('mfg_issues', function (Blueprint $table) use ($userRef, $currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('order_id')->constrained('mfg_orders')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('ledger', 10);
            $table->text('note')->nullable();
            $userRef($table, 'issued_by', nullable: false);
            $table->timestampTz('issued_at', 6);
            $table->timestampTz('valued_at')->nullable();
            $currency($table);
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'location_id', 'issued_at']);
        });
        DB::statement("alter table mfg_issues add constraint mfg_issues_check check (ledger in ('inventory', 'none'))");
        Rls::enable('mfg_issues');

        Schema::create('mfg_issue_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('issue_id')->constrained('mfg_issues')->restrictOnDelete();
            $table->foreignUuid('material_id')->constrained('mfg_order_materials')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $qty($table, 'qty');
            $qty($table, 'qty_base');
            $qty($table, 'qty_returned_base')->default(0);
            $table->bigInteger('value_minor')->nullable();
            $table->bigInteger('cost_variance_minor')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'issue_id']);
        });
        DB::statement('alter table mfg_issue_lines add constraint mfg_issue_lines_check check (qty > 0 and qty_base > 0 and qty_returned_base >= 0 and qty_returned_base <= qty_base)');
        Rls::enable('mfg_issue_lines');

        // MFG-12: unused materials back to the location.
        Schema::create('mfg_returns', function (Blueprint $table) use ($userRef, $currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('order_id')->constrained('mfg_orders')->restrictOnDelete();
            $table->foreignUuid('issue_id')->constrained('mfg_issues')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('ledger', 10);
            $table->string('reason', 20);
            $table->text('note')->nullable();
            $userRef($table, 'returned_by', nullable: false);
            $table->timestampTz('returned_at', 6);
            $table->timestampTz('valued_at')->nullable();
            $currency($table);
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
        });
        DB::statement("alter table mfg_returns add constraint mfg_returns_check check (ledger in ('inventory', 'none') and reason in ('unused', 'excess', 'wrong_item', 'other'))");
        Rls::enable('mfg_returns');

        Schema::create('mfg_return_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('return_id')->constrained('mfg_returns')->restrictOnDelete();
            $table->foreignUuid('issue_line_id')->constrained('mfg_issue_lines')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $qty($table, 'qty');
            $qty($table, 'qty_base');
            $table->bigInteger('value_minor')->nullable();
            $table->bigInteger('cost_variance_minor')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'return_id']);
        });
        DB::statement('alter table mfg_return_lines add constraint mfg_return_lines_check check (qty > 0 and qty_base > 0)');
        Rls::enable('mfg_return_lines');

        // MFG-13, MFG-16: production reported against an order, costed once its materials are valued.
        Schema::create('mfg_reports', function (Blueprint $table) use ($qty, $userRef, $currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('order_id')->constrained('mfg_orders')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('ledger', 10);
            $table->string('status', 12);
            $qty($table, 'qty_good');
            $qty($table, 'qty_good_base');
            $qty($table, 'qty_scrap');
            $qty($table, 'qty_scrap_base');
            $table->string('scrap_reason', 20)->nullable();
            $table->integer('labour_minutes')->default(0);
            $table->bigInteger('material_cost_minor')->nullable();
            $table->bigInteger('labour_cost_minor')->nullable();
            $table->bigInteger('overhead_cost_minor')->nullable();
            $table->bigInteger('cost_minor')->nullable();
            $table->text('note')->nullable();
            $userRef($table, 'reported_by', nullable: false);
            $table->timestampTz('reported_at', 6);
            $table->timestampTz('costed_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $currency($table);
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'location_id', 'reported_at']);
        });
        DB::statement("alter table mfg_reports add constraint mfg_reports_check check (ledger in ('inventory', 'none') and status in ('recorded', 'pending', 'costed', 'received') and qty_good >= 0 and qty_scrap >= 0 and qty_good_base >= 0 and qty_scrap_base >= 0 and labour_minutes >= 0 and (scrap_reason is null or scrap_reason in ('damaged', 'quality', 'process', 'other')))");
        Rls::enable('mfg_reports');

        Schema::create('mfg_report_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('report_id')->constrained('mfg_reports')->restrictOnDelete();
            $table->foreignUuid('output_id')->nullable()->constrained('mfg_order_outputs')->restrictOnDelete();
            $table->integer('line_no');
            $table->string('kind', 12);
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $qty($table, 'qty_base');
            $table->bigInteger('cost_minor')->nullable();
            $table->bigInteger('cost_variance_minor')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'report_id']);
        });
        DB::statement("alter table mfg_report_lines add constraint mfg_report_lines_check check (kind in ('product', 'by_product', 'scrap') and qty_base > 0)");
        Rls::enable('mfg_report_lines');

        // MFG-07, MFG-08: ingredients a POS sale consumed through its recipes, and what its void or refunds gave back.
        Schema::create('mfg_backflushes', function (Blueprint $table) use ($currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('source_type', 20);
            $table->uuid('source_id');
            $table->uuid('sale_id');
            $table->string('kind', 10);
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('reference', 80)->nullable();
            $table->timestampTz('occurred_at', 6);
            // Sale lines not backflushed and why: [{sale_line_id, item_id, code}].
            $table->jsonb('skipped')->nullable();
            $table->timestampTz('valued_at')->nullable();
            $currency($table);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'source_type', 'source_id']);
            $table->index(['tenant_id', 'sale_id']);
            $table->index(['tenant_id', 'location_id', 'occurred_at']);
        });
        DB::statement("alter table mfg_backflushes add constraint mfg_backflushes_check check (source_type in ('pos.sale', 'pos.void', 'pos.refund') and kind in ('consume', 'return'))");
        Rls::enable('mfg_backflushes');

        Schema::create('mfg_backflush_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('backflush_id')->constrained('mfg_backflushes')->restrictOnDelete();
            $table->integer('line_no');
            $table->uuid('sale_line_id');
            $table->foreignUuid('sold_item_id')->constrained('items')->restrictOnDelete();
            $table->foreignUuid('bom_version_id')->constrained('mfg_bom_versions')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $qty($table, 'qty_base');
            $qty($table, 'qty_returned_base')->default(0);
            // MFG-08: the sale line's quantity this line accounts for (sold, or refunded), in the sale line's unit.
            $qty($table, 'sale_qty')->default(0);
            // The consumed line a return gives back (its foreign key is added once the table exists).
            $table->uuid('origin_line_id')->nullable();
            $table->bigInteger('value_minor')->nullable();
            $table->bigInteger('cost_variance_minor')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'backflush_id']);
            $table->index(['tenant_id', 'origin_line_id']);
        });
        DB::statement('alter table mfg_backflush_lines add constraint mfg_backflush_lines_origin_line_id_foreign foreign key (origin_line_id) references mfg_backflush_lines (id) on delete restrict');
        DB::statement('alter table mfg_backflush_lines add constraint mfg_backflush_lines_check check (qty_base > 0 and qty_returned_base >= 0 and qty_returned_base <= qty_base)');
        Rls::enable('mfg_backflush_lines');

        // MFG-15: Inventory's movement kinds for Manufacturing's documents (Inventory's lookup, when installed).
        if (Schema::hasTable('inventory_movement_kinds')) {
            DB::table('inventory_movement_kinds')->insertOrIgnore(array_map(fn (string $kind) => ['kind' => $kind], self::KINDS));
        }
    }

    public function down(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        if (Schema::hasTable('inventory_movement_kinds')) {
            DB::table('inventory_movement_kinds')->whereIn('kind', self::KINDS)->delete();
        }

        foreach ([
            'mfg_backflush_lines', 'mfg_backflushes', 'mfg_report_lines', 'mfg_reports', 'mfg_return_lines', 'mfg_returns',
            'mfg_issue_lines', 'mfg_issues', 'mfg_order_outputs', 'mfg_order_materials', 'mfg_orders',
            'mfg_bom_lines', 'mfg_bom_versions', 'mfg_boms', 'mfg_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
