<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Stores module (docs/modules/stores.md). Every table is the tenant's,
// under forced row-level security (TEN-01). Quantities are exact decimals,
// numeric(20,6), as entered and in the item's base unit; values are bigint
// minor units with their currency (ADR 003), known only when Inventory
// valued the movement (STR-09). The store ledger (stores_movements) is
// append-only: a trigger refuses deletes, truncates and every update except
// writing a value once into a row that has none (STR-07). Users are
// referenced by (tenant_id, id). Business records are never deleted
// (TEN-06): requisitions end closed, rejected or cancelled.
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

        // STR-02, STR-03, STR-04: a department's request to a store.
        Schema::create('stores_requisitions', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            $table->date('needed_by')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20);
            $userRef($table, 'requested_by', nullable: false);
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $userRef($table, 'closed_by');
            $table->timestampTz('closed_at')->nullable();
            $table->text('close_reason')->nullable();
            $userRef($table, 'cancelled_by');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id', 'created_at']);
            $table->index(['tenant_id', 'department_id']);
        });
        DB::statement("alter table stores_requisitions add constraint stores_requisitions_status_check check (status in ('pending', 'approved', 'partially_issued', 'issued', 'closed', 'rejected', 'cancelled'))");
        Rls::enable('stores_requisitions');

        Schema::create('stores_requisition_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('requisition_id')->constrained('stores_requisitions')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $qty($table, 'qty');
            $qty($table, 'qty_base');
            // In the line's unit, over every issue.
            $qty($table, 'qty_issued')->default(0);
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'requisition_id']);
        });
        DB::statement('alter table stores_requisition_lines add constraint stores_requisition_lines_check check (qty > 0 and qty_base > 0 and qty_issued >= 0 and qty_issued <= qty)');
        Rls::enable('stores_requisition_lines');

        // STR-04, STR-05: goods handed to the department, in full or in part.
        Schema::create('stores_issues', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('requisition_id')->constrained('stores_requisitions')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            // STR-07, STR-08: whose ledger moved the goods.
            $table->string('ledger', 10);
            $table->string('status', 20);
            $table->text('note')->nullable();
            $userRef($table, 'issued_by', nullable: false);
            $table->timestampTz('issued_at', 6);
            $userRef($table, 'acknowledged_by');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->text('acknowledge_note')->nullable();
            // STR-09: when Inventory's values were recorded, and in which currency.
            $table->timestampTz('valued_at')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id', 'issued_at']);
            $table->index(['tenant_id', 'requisition_id']);
        });
        DB::statement("alter table stores_issues add constraint stores_issues_check check (status in ('issued', 'acknowledged') and ledger in ('stores', 'inventory'))");
        Rls::enable('stores_issues');

        Schema::create('stores_issue_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('issue_id')->constrained('stores_issues')->restrictOnDelete();
            $table->foreignUuid('requisition_line_id')->constrained('stores_requisition_lines')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $qty($table, 'qty');
            $qty($table, 'qty_base');
            $qty($table, 'qty_returned_base')->default(0);
            $table->bigInteger('value_minor')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'issue_id']);
        });
        DB::statement('alter table stores_issue_lines add constraint stores_issue_lines_check check (qty > 0 and qty_base > 0 and qty_returned_base >= 0 and qty_returned_base <= qty_base)');
        Rls::enable('stores_issue_lines');

        // STR-06: goods back to the store against an issue.
        Schema::create('stores_returns', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('issue_id')->constrained('stores_issues')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            $table->string('ledger', 10);
            $table->string('reason', 20);
            $table->text('note')->nullable();
            $userRef($table, 'returned_by', nullable: false);
            $table->timestampTz('returned_at', 6);
            $table->timestampTz('valued_at')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id', 'returned_at']);
            $table->index(['tenant_id', 'issue_id']);
        });
        DB::statement("alter table stores_returns add constraint stores_returns_check check (ledger in ('stores', 'inventory') and reason in ('unused', 'excess', 'wrong_item', 'damaged', 'other'))");
        Rls::enable('stores_returns');

        Schema::create('stores_return_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('return_id')->constrained('stores_returns')->restrictOnDelete();
            $table->foreignUuid('issue_line_id')->constrained('stores_issue_lines')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $qty($table, 'qty');
            $qty($table, 'qty_base');
            $table->bigInteger('value_minor')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'return_id']);
        });
        DB::statement('alter table stores_return_lines add constraint stores_return_lines_check check (qty > 0 and qty_base > 0)');
        Rls::enable('stores_return_lines');

        // STR-07, STR-10: the store ledger, append-only.
        Schema::create('stores_movements', function (Blueprint $table) use ($qty, $userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            // The order movements happened in, per store and item (the balance follows it).
            $table->bigInteger('seq')->generatedAs();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('ledger', 10);
            $qty($table, 'qty');
            $table->foreignUuid('uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $qty($table, 'uom_qty')->nullable();
            // The store's quantity after this movement (own ledger only).
            $qty($table, 'balance_after')->nullable();
            $table->bigInteger('value_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('source_type', 40);
            $table->uuid('source_id');
            $table->uuid('source_line_id')->nullable();
            $table->string('reference', 80)->nullable();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            $table->string('reason', 40)->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('occurred_at', 6);
            $userRef($table, 'created_by');
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['tenant_id', 'location_id', 'item_id', 'seq']);
            $table->index(['tenant_id', 'item_id', 'occurred_at']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });
        DB::statement("alter table stores_movements add constraint stores_movements_check check (qty <> 0 and kind in ('opening', 'receipt', 'adjustment_in', 'adjustment_out', 'issue', 'return') and ledger in ('stores', 'inventory') and (value_minor is null) = (currency is null))");
        // Idempotency: one movement per source line and kind.
        DB::statement('create unique index stores_movements_once on stores_movements (tenant_id, source_type, source_id, source_line_id, kind) nulls not distinct');
        Rls::enable('stores_movements');

        DB::unprepared(<<<'SQL'
            create or replace function stores_ledger_append_only() returns trigger
            language plpgsql as $$
            begin
                -- STR-09: a value is written once into a movement that has none; nothing else changes.
                if tg_op = 'UPDATE' and old.value_minor is null and new.value_minor is not null
                   and (to_jsonb(new) - 'value_minor' - 'currency') = (to_jsonb(old) - 'value_minor' - 'currency') then
                    return new;
                end if;

                raise exception 'the store ledger is append-only' using errcode = 'insufficient_privilege';
            end;
            $$;

            create trigger stores_movements_append_only before update or delete on stores_movements
                for each row execute function stores_ledger_append_only();
            create trigger stores_movements_no_truncate before truncate on stores_movements
                for each statement execute function stores_ledger_append_only();
            SQL);
        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("revoke delete, truncate on stores_movements from {$runtimeRole}");
    }

    public function down(): void
    {
        foreach (['stores_movements', 'stores_return_lines', 'stores_returns', 'stores_issue_lines', 'stores_issues', 'stores_requisition_lines', 'stores_requisitions'] as $table) {
            Schema::dropIfExists($table);
        }

        DB::statement('drop function if exists stores_ledger_append_only()');
    }
};
