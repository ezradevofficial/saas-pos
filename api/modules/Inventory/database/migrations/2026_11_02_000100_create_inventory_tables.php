<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Inventory module (docs/modules/inventory.md). Every table is the
// tenant's, under forced row-level security (TEN-01). Quantities are exact
// decimals in the item's base unit, numeric(20,6) (INV-03); costs are
// bigint minor units of the company's base currency with its code
// (ADR 003). The stock ledger (inventory_movements) is append-only: a
// trigger refuses updates, deletes and truncates for every role, the owner
// included (INV-04). Users are referenced by (tenant_id, id), so a row never
// points at another tenant's user. Business records are never deleted
// (TEN-06): documents end cancelled or rejected, reorder rules are archived.
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

        // INV-12, INV-13: per company, its costing method and negative-stock rule.
        Schema::create('inventory_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('costing_method', 20);
            $table->boolean('allow_negative')->default(true);
            // Set by the company's first movement: the method no longer changes.
            $table->timestampTz('costing_locked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id']);
        });
        DB::statement("alter table inventory_settings add constraint inventory_settings_method_check check (costing_method in ('fifo', 'weighted_average'))");
        Rls::enable('inventory_settings');

        // INV-01: an item's tracking (core items stay untouched).
        Schema::create('inventory_item_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->string('tracking', 10)->default('none');
            $table->boolean('track_expiry')->default(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'item_id']);
        });
        DB::statement("alter table inventory_item_settings add constraint inventory_item_settings_tracking_check check (tracking in ('none', 'batch', 'serial'))");
        Rls::enable('inventory_item_settings');

        // INV-02: a variant item linked to its parent, with its attribute values.
        Schema::create('inventory_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('parent_item_id')->constrained('items')->restrictOnDelete();
            $table->jsonb('attributes');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'item_id']);
            $table->index(['tenant_id', 'parent_item_id']);
        });
        DB::statement("alter table inventory_variants add constraint inventory_variants_attributes_check check (jsonb_typeof(attributes) = 'object' and item_id <> parent_item_id)");
        DB::statement('create unique index inventory_variants_unique_attributes on inventory_variants (tenant_id, parent_item_id, attributes)');
        Rls::enable('inventory_variants');

        // INV-06: batches of an item, numbers unique per item ignoring case.
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->string('batch_number', 60);
            $table->date('expires_on')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'item_id', 'expires_on']);
        });
        DB::statement('create unique index inventory_batches_unique_number on inventory_batches (tenant_id, item_id, lower(batch_number))');
        Rls::enable('inventory_batches');

        // INV-07: serial numbers of an item, where each one is now.
        Schema::create('inventory_serials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->string('serial_number', 100);
            $table->string('status', 12);
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'item_id', 'location_id', 'status']);
        });
        DB::statement("alter table inventory_serials add constraint inventory_serials_status_check check (status in ('in_stock', 'in_transit', 'issued'))");
        DB::statement('create unique index inventory_serials_unique_number on inventory_serials (tenant_id, item_id, lower(serial_number))');
        Rls::enable('inventory_serials');

        // INV-04, INV-05, INV-17: stock on hand per location and item, moved
        // only with the ledger (same transaction) and pulled by tills.
        Schema::create('inventory_balances', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $qty($table, 'qty')->default(0);
            $table->bigInteger('sync_xid')->default(0);
            $table->bigInteger('sync_seq')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'location_id', 'item_id']);
            $table->index(['tenant_id', 'item_id']);
            $table->index(['tenant_id', 'sync_xid', 'sync_seq'], 'inventory_balances_sync_cursor');
        });
        DB::statement('create trigger sync_stamp before insert or update on inventory_balances for each row execute function sync_stamp()');
        Rls::enable('inventory_balances');

        // INV-06: stock per batch at a location.
        Schema::create('inventory_batch_balances', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('batch_id')->constrained('inventory_batches')->restrictOnDelete();
            $qty($table, 'qty')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'location_id', 'item_id', 'batch_id']);
        });
        Rls::enable('inventory_batch_balances');

        // INV-04: the append-only stock ledger.
        Schema::create('inventory_movements', function (Blueprint $table) use ($qty, $userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            // Null while in transit (a transfer's loss).
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('inventory_batches')->restrictOnDelete();
            $table->string('kind', 20);
            $qty($table, 'qty');
            // As entered: the unit and the quantity in it.
            $table->foreignUuid('uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $qty($table, 'uom_qty')->nullable();
            $table->bigInteger('value_minor');
            $table->char('currency', 3);
            $table->bigInteger('cost_variance_minor')->default(0);
            $qty($table, 'balance_after')->nullable();
            $table->string('source_type', 40);
            $table->uuid('source_id');
            $table->uuid('source_line_id')->nullable();
            // A return: the movement whose cost it takes back.
            $table->uuid('reverses_movement_id')->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->jsonb('flags')->default('[]');
            $userRef($table, 'created_by');
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['tenant_id', 'item_id', 'occurred_at']);
            $table->index(['tenant_id', 'location_id', 'occurred_at']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });
        DB::statement("alter table inventory_movements add constraint inventory_movements_kind_check check (kind in ('opening', 'opening_reversal', 'receipt', 'adjustment_in', 'adjustment_out', 'count_gain', 'count_loss', 'transfer_out', 'transfer_in', 'transfer_loss', 'sale', 'sale_return', 'sale_void'))");
        DB::statement("alter table inventory_movements add constraint inventory_movements_flags_check check (jsonb_typeof(flags) = 'array' and qty <> 0)");
        // Idempotency: one movement per source line, kind and batch.
        DB::statement('create unique index inventory_movements_once on inventory_movements (tenant_id, source_type, source_id, source_line_id, kind, batch_id) nulls not distinct');
        Rls::enable('inventory_movements');

        Schema::create('inventory_movement_serials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignUuid('serial_id')->constrained('inventory_serials')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'movement_id', 'serial_id']);
            $table->index(['tenant_id', 'serial_id']);
        });
        Rls::enable('inventory_movement_serials');

        DB::unprepared(<<<'SQL'
            create or replace function inventory_ledger_append_only() returns trigger
            language plpgsql as $$
            begin
                raise exception 'the stock ledger is append-only' using errcode = 'insufficient_privilege';
            end;
            $$;

            create trigger inventory_movements_append_only before update or delete on inventory_movements
                for each row execute function inventory_ledger_append_only();
            create trigger inventory_movements_no_truncate before truncate on inventory_movements
                for each statement execute function inventory_ledger_append_only();
            create trigger inventory_movement_serials_append_only before update or delete on inventory_movement_serials
                for each row execute function inventory_ledger_append_only();
            create trigger inventory_movement_serials_no_truncate before truncate on inventory_movement_serials
                for each statement execute function inventory_ledger_append_only();
            SQL);
        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("revoke update, delete, truncate on inventory_movements, inventory_movement_serials from {$runtimeRole}");

        // INV-12: what a company holds of an item and its value.
        Schema::create('inventory_cost_states', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $qty($table, 'qty')->default(0);
            $table->bigInteger('value_minor')->default(0);
            $table->char('currency', 3);
            // The last receipt's cost and quantity (its unit cost, exactly).
            $table->bigInteger('last_cost_minor')->nullable();
            $qty($table, 'last_cost_qty')->nullable();
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['tenant_id', 'company_id', 'item_id']);
        });
        Rls::enable('inventory_cost_states');

        // INV-12: FIFO layers, consumed oldest first.
        Schema::create('inventory_cost_layers', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $table->timestampTz('received_at', 6);
            $qty($table, 'qty');
            $table->bigInteger('cost_minor');
            $qty($table, 'remaining_qty');
            $table->bigInteger('remaining_cost_minor');
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'item_id', 'received_at']);
        });
        DB::statement('alter table inventory_cost_layers add constraint inventory_cost_layers_remaining_check check (remaining_qty >= 0 and remaining_qty <= qty)');
        // The layer is made while costing, just before its movement row, in the same transaction.
        DB::statement('alter table inventory_cost_layers alter constraint inventory_cost_layers_movement_id_foreign deferrable initially deferred');
        Rls::enable('inventory_cost_layers');

        // INV-11: reorder level and quantity per item and location.
        Schema::create('inventory_reorder_rules', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $qty($table, 'reorder_level');
            $qty($table, 'reorder_qty');
            // Set when stock fell to the level (alert sent), cleared above it.
            $table->timestampTz('low_since')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'item_id', 'location_id']);
        });
        DB::statement('alter table inventory_reorder_rules add constraint inventory_reorder_rules_levels_check check (reorder_level >= 0 and reorder_qty >= 0)');
        DB::statement('create unique index inventory_reorder_rules_one_active on inventory_reorder_rules (tenant_id, location_id, item_id) where archived_at is null');
        Rls::enable('inventory_reorder_rules');

        // INV-08: transfers between two locations of one company.
        Schema::create('inventory_transfers', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('from_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignUuid('to_location_id')->constrained('locations')->restrictOnDelete();
            $table->string('status', 12);
            $table->text('note')->nullable();
            $userRef($table, 'requested_by', nullable: false);
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $userRef($table, 'dispatched_by');
            $table->timestampTz('dispatched_at')->nullable();
            $userRef($table, 'received_by');
            $table->timestampTz('received_at')->nullable();
            $table->text('loss_reason')->nullable();
            $userRef($table, 'cancelled_by');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'created_at']);
            $table->index(['tenant_id', 'from_location_id']);
            $table->index(['tenant_id', 'to_location_id']);
        });
        DB::statement("alter table inventory_transfers add constraint inventory_transfers_status_check check (status in ('pending', 'approved', 'rejected', 'in_transit', 'received', 'cancelled') and from_location_id <> to_location_id)");
        Rls::enable('inventory_transfers');

        Schema::create('inventory_transfer_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('transfer_id')->constrained('inventory_transfers')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $qty($table, 'qty');
            $qty($table, 'qty_base');
            $table->foreignUuid('batch_id')->nullable()->constrained('inventory_batches')->restrictOnDelete();
            $table->jsonb('serials')->default('[]');
            $qty($table, 'qty_received')->nullable();
            $qty($table, 'qty_lost')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'transfer_id']);
        });
        DB::statement("alter table inventory_transfer_lines add constraint inventory_transfer_lines_check check (qty > 0 and qty_base > 0 and jsonb_typeof(serials) = 'array')");
        Rls::enable('inventory_transfer_lines');

        // INV-10: adjustments with a reason, posted on approval.
        Schema::create('inventory_adjustments', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('reason', 20);
            $table->text('note')->nullable();
            $table->string('status', 12);
            $userRef($table, 'requested_by', nullable: false);
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $userRef($table, 'cancelled_by');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id', 'created_at']);
        });
        DB::statement("alter table inventory_adjustments add constraint inventory_adjustments_status_check check (status in ('pending', 'posted', 'rejected', 'cancelled') and reason in ('damage', 'expiry', 'theft', 'found', 'correction', 'internal_use', 'other'))");
        Rls::enable('inventory_adjustments');

        Schema::create('inventory_adjustment_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('adjustment_id')->constrained('inventory_adjustments')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            // Signed: positive adds stock, negative takes it out.
            $qty($table, 'qty');
            $qty($table, 'qty_base');
            $table->foreignUuid('batch_id')->nullable()->constrained('inventory_batches')->restrictOnDelete();
            $table->string('batch_number', 60)->nullable();
            $table->date('expires_on')->nullable();
            $table->jsonb('serials')->default('[]');
            // A typed unit cost per entered unit (major units, increases only).
            $table->decimal('unit_cost', 24, 6)->nullable();
            $table->bigInteger('value_minor')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'adjustment_id']);
        });
        DB::statement("alter table inventory_adjustment_lines add constraint inventory_adjustment_lines_check check (qty <> 0 and qty_base <> 0 and jsonb_typeof(serials) = 'array')");
        Rls::enable('inventory_adjustment_lines');

        // INV-09: stock counts, frozen while counting.
        Schema::create('inventory_counts', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80);
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('status', 12);
            $table->text('note')->nullable();
            $userRef($table, 'requested_by', nullable: false);
            $table->timestampTz('frozen_at', 6);
            $userRef($table, 'submitted_by');
            $table->timestampTz('submitted_at')->nullable();
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $userRef($table, 'cancelled_by');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id', 'status']);
        });
        DB::statement("alter table inventory_counts add constraint inventory_counts_check check (status in ('counting', 'pending', 'posted', 'rejected', 'cancelled') and kind in ('full', 'cycle'))");
        Rls::enable('inventory_counts');

        Schema::create('inventory_count_lines', function (Blueprint $table) use ($qty) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('count_id')->constrained('inventory_counts')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('inventory_batches')->restrictOnDelete();
            $qty($table, 'expected_qty');
            $qty($table, 'counted_qty')->nullable();
            $qty($table, 'variance_qty')->nullable();
            $table->bigInteger('variance_value_minor')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'count_id']);
        });
        DB::statement('create unique index inventory_count_lines_once on inventory_count_lines (tenant_id, count_id, item_id, batch_id) nulls not distinct');
        Rls::enable('inventory_count_lines');
    }

    public function down(): void
    {
        foreach ([
            'inventory_count_lines', 'inventory_counts', 'inventory_adjustment_lines', 'inventory_adjustments',
            'inventory_transfer_lines', 'inventory_transfers', 'inventory_reorder_rules', 'inventory_cost_layers',
            'inventory_cost_states', 'inventory_movement_serials', 'inventory_movements', 'inventory_batch_balances',
            'inventory_balances', 'inventory_serials', 'inventory_batches', 'inventory_variants', 'inventory_item_settings',
            'inventory_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        DB::statement('drop function if exists inventory_ledger_append_only()');
    }
};
