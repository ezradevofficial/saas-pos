<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Pharmacy module (docs/modules/pharmacy.md, PHA-01 to PHA-30). Every table
// is the tenant's, under forced row-level security (TEN-01). Quantities are
// numeric(20,6) in the item's base unit; costs are bigint minor units with
// their currency (ADR 003). The lot ledger and the controlled-drug register
// are append-only (a trigger refuses update and delete, also for the owner).
// POS sales are named by id only: this module never references POS tables
// (CLAUDE.md, modular monolith). Users are referenced by (tenant_id, id).
return new class extends Migration
{
    private const LEDGERS = ['pharmacy_lot_movements', 'pharmacy_register_entries'];

    public function up(): void
    {
        $userRef = function (Blueprint $table, string $column, bool $nullable = true): void {
            $definition = $table->uuid($column);

            if ($nullable) {
                $definition->nullable();
            }

            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        };

        // PHA-02: one row of settings per company.
        Schema::create('pharmacy_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->unique()->constrained()->restrictOnDelete();
            $table->integer('near_expiry_days')->default(90);
            $table->integer('min_sale_days')->default(0);
            $table->jsonb('prescription_classes')->default('["prescription_only","controlled"]');
            $table->jsonb('pharmacist_classes')->default('["prescription_only","controlled","poison"]');
            $table->integer('prescription_valid_days')->nullable();
            $table->boolean('quarantine_expired')->default(true);
            $table->timestampsTz();
        });

        DB::statement('alter table pharmacy_settings add constraint pharmacy_settings_values_check check (near_expiry_days between 0 and 730 and min_sale_days between 0 and 365
            and (prescription_valid_days is null or prescription_valid_days between 1 and 3650))');
        Rls::enable('pharmacy_settings');

        // PHA-03, PHA-20, PHA-21: the item's pharmacy profile (synced to tills).
        Schema::create('pharmacy_item_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('item_id')->unique()->constrained('items')->restrictOnDelete();
            $table->string('class', 20)->default('otc');
            $table->string('product_kind', 12)->default('human');
            $table->boolean('batch_tracked')->default(true);
            $table->boolean('restricted_sale')->default(false);
            $table->text('usage_notes')->nullable();
            $table->string('schedule_note', 200)->nullable();
            $table->integer('withdrawal_days')->nullable();
            $table->bigInteger('sync_xid')->default(0);
            $table->bigInteger('sync_seq')->default(0);
            $table->timestampsTz();
        });

        DB::statement("alter table pharmacy_item_profiles add constraint pharmacy_item_profiles_values_check check (class in ('otc', 'prescription_only', 'controlled', 'poison', 'agrochemical')
            and product_kind in ('human', 'veterinary', 'agrochemical', 'other') and (withdrawal_days is null or withdrawal_days between 0 and 3650))");
        DB::statement('create trigger sync_stamp before insert or update on pharmacy_item_profiles for each row execute function sync_stamp()');
        DB::statement('create index pharmacy_item_profiles_sync_cursor on pharmacy_item_profiles (tenant_id, sync_xid, sync_seq)');
        Rls::enable('pharmacy_item_profiles');

        // PHA-22: a recall names an item and a batch number, tenant-wide.
        Schema::create('pharmacy_recalls', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->string('batch_number', 60);
            $table->string('reference', 100)->nullable();
            $table->text('reason');
            $table->string('status', 10)->default('open');
            $userRef($table, 'issued_by');
            $table->timestampTz('issued_at');
            $userRef($table, 'lifted_by');
            $table->timestampTz('lifted_at')->nullable();
            $table->text('lift_reason')->nullable();
            $table->timestampsTz();

            $table->index(['item_id', 'status']);
        });

        DB::statement("alter table pharmacy_recalls add constraint pharmacy_recalls_status_check check (status in ('open', 'lifted') and (status = 'lifted') = (lifted_at is not null))");
        DB::statement("create unique index pharmacy_recalls_one_open on pharmacy_recalls (item_id, lower(batch_number)) where status = 'open'");
        Rls::enable('pharmacy_recalls');

        // PHA-04: a lot (batch) of an item at a company.
        Schema::create('pharmacy_lots', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->string('batch_number', 60);
            $table->date('manufactured_on')->nullable();
            $table->date('expires_on');
            $table->foreignUuid('supplier_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('status', 12)->default('active');
            $table->text('quarantine_reason')->nullable();
            $table->timestampTz('quarantined_at')->nullable();
            $userRef($table, 'quarantined_by');
            $table->foreignUuid('recall_id')->nullable()->constrained('pharmacy_recalls')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['item_id', 'expires_on']);
            $table->index(['company_id', 'expires_on']);
            $table->index('supplier_id');
            $table->index('recall_id');
        });

        DB::statement("alter table pharmacy_lots add constraint pharmacy_lots_values_check check (status in ('active', 'quarantined') and (manufactured_on is null or manufactured_on <= expires_on)
            and ((status = 'quarantined') = (quarantined_at is not null)))");
        DB::statement('create unique index pharmacy_lots_batch_unique on pharmacy_lots (company_id, item_id, lower(batch_number))');
        DB::statement('create index pharmacy_lots_batch_lookup on pharmacy_lots (item_id, lower(batch_number))');
        Rls::enable('pharmacy_lots');

        // PHA-05: stock of a lot at a location (synced to tills).
        Schema::create('pharmacy_lot_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignUuid('lot_id')->constrained('pharmacy_lots')->restrictOnDelete();
            $table->decimal('qty', 20, 6)->default(0);
            $table->bigInteger('sync_xid')->default(0);
            $table->bigInteger('sync_seq')->default(0);
            $table->timestampsTz();

            $table->unique(['lot_id', 'location_id']);
            $table->index(['location_id', 'item_id']);
            $table->index('item_id');
            $table->index('company_id');
        });

        DB::statement('create trigger sync_stamp before insert or update on pharmacy_lot_balances for each row execute function sync_stamp()');
        DB::statement('create index pharmacy_lot_balances_sync_cursor on pharmacy_lot_balances (tenant_id, sync_xid, sync_seq)');
        Rls::enable('pharmacy_lot_balances');

        // PHA-05: the append-only ledger of lot stock.
        Schema::create('pharmacy_lot_movements', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignUuid('lot_id')->constrained('pharmacy_lots')->restrictOnDelete();
            $table->string('kind', 16);
            $table->decimal('qty', 20, 6);
            $table->string('source_type', 40);
            $table->uuid('source_id')->nullable();
            $table->uuid('source_line_id')->nullable();
            // The POS sale it belongs to, by id only (sales, voids and refunds all carry it).
            $table->uuid('sale_id')->nullable();
            // The printed number of the sale, receipt or document, for people (recall customer lists).
            $table->string('reference', 60)->nullable();
            $table->foreignUuid('customer_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->uuid('prescription_id')->nullable();
            $table->jsonb('flags')->default('[]');
            $table->text('note')->nullable();
            $userRef($table, 'created_by');
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['lot_id', 'occurred_at']);
            $table->index(['location_id', 'item_id', 'occurred_at']);
            $table->index('item_id');
            $table->index('sale_id');
            $table->index('customer_id');
            $table->index('company_id');
        });

        DB::statement("alter table pharmacy_lot_movements add constraint pharmacy_lot_movements_kind_check check (kind in ('receipt', 'sale', 'sale_void', 'sale_return', 'transfer_out', 'transfer_in', 'write_off', 'adjustment') and qty <> 0)");
        DB::statement("create unique index pharmacy_lot_movements_once on pharmacy_lot_movements (lot_id, location_id, kind, source_type, source_id, coalesce(source_line_id, '00000000-0000-0000-0000-000000000000'::uuid)) where source_id is not null");
        Rls::enable('pharmacy_lot_movements');

        // PHA-06: back-office receipts.
        Schema::create('pharmacy_receipts', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('reference', 100)->nullable();
            $table->timestampTz('received_at');
            $table->text('note')->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('branch_id');
            $table->index('location_id');
            $table->index('supplier_id');
        });
        Rls::enable('pharmacy_receipts');

        Schema::create('pharmacy_receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('receipt_id')->constrained('pharmacy_receipts')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignUuid('lot_id')->constrained('pharmacy_lots')->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('entered_qty', 20, 6);
            $table->decimal('qty', 20, 6);
            $table->bigInteger('unit_cost_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestampsTz();

            $table->unique(['receipt_id', 'line_no']);
            $table->index('item_id');
            $table->index('lot_id');
            $table->index('uom_id');
            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        DB::statement('alter table pharmacy_receipt_lines add constraint pharmacy_receipt_lines_values_check check (qty > 0 and entered_qty > 0 and (unit_cost_minor is null or unit_cost_minor >= 0) and ((unit_cost_minor is null) = (currency is null)))');
        Rls::enable('pharmacy_receipt_lines');

        // PHA-12: write-offs (a workflow document).
        Schema::create('pharmacy_write_offs', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 40);
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('reason', 12);
            $table->text('note')->nullable();
            $table->string('status', 12)->default('pending');
            $userRef($table, 'requested_by', false);
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $userRef($table, 'cancelled_by');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->string('failure', 60)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number']);
            $table->index('company_id');
            $table->index('branch_id');
            $table->index(['location_id', 'status']);
        });

        DB::statement("alter table pharmacy_write_offs add constraint pharmacy_write_offs_values_check check (reason in ('expired', 'damaged', 'recalled', 'other')
            and status in ('pending', 'posted', 'rejected', 'cancelled', 'failed'))");
        Rls::enable('pharmacy_write_offs');

        Schema::create('pharmacy_write_off_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('write_off_id')->constrained('pharmacy_write_offs')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignUuid('lot_id')->constrained('pharmacy_lots')->restrictOnDelete();
            $table->decimal('qty', 20, 6);
            $table->timestampsTz();

            $table->unique(['write_off_id', 'line_no']);
            $table->index('item_id');
            $table->index('lot_id');
        });

        DB::statement('alter table pharmacy_write_off_lines add constraint pharmacy_write_off_lines_qty_check check (qty > 0)');
        Rls::enable('pharmacy_write_off_lines');

        // PHA-14, PHA-15, PHA-28: prescriptions (personal data).
        Schema::create('pharmacy_prescriptions', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('prescriber_name', 150);
            $table->string('prescriber_licence', 60);
            $table->string('facility', 150);
            $table->string('patient_name', 150);
            $table->string('patient_phone', 30)->nullable();
            $table->date('patient_birth_date')->nullable();
            $table->foreignUuid('patient_party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->date('prescribed_on');
            $table->text('notes')->nullable();
            $table->string('status', 10)->default('valid');
            $table->string('source', 10);
            $table->uuid('sale_id')->nullable();
            $table->string('image_disk', 40)->nullable();
            $table->string('image_path', 500)->nullable();
            $table->string('image_name', 255)->nullable();
            $table->string('image_mime', 100)->nullable();
            $table->integer('image_size')->nullable();
            $userRef($table, 'image_by');
            $table->timestampTz('cancelled_at')->nullable();
            $userRef($table, 'cancelled_by');
            $table->text('cancel_reason')->nullable();
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'prescribed_on']);
            $table->index('branch_id');
            $table->index('location_id');
            $table->index('patient_party_id');
            $table->index('sale_id');
        });

        DB::statement("alter table pharmacy_prescriptions add constraint pharmacy_prescriptions_values_check check (status in ('valid', 'cancelled') and source in ('till', 'office')
            and ((image_path is null) = (image_disk is null)))");
        Rls::enable('pharmacy_prescriptions');

        // PHA-16, PHA-17, PHA-26: what was dispensed against a rule, and what the server found.
        Schema::create('pharmacy_dispenses', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->uuid('sale_id');
            $table->uuid('sale_line_id');
            $table->string('receipt_number', 60)->nullable();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->string('class', 20);
            $table->decimal('qty', 20, 6);
            $table->foreignUuid('customer_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('prescription_id')->nullable()->constrained('pharmacy_prescriptions')->restrictOnDelete();
            $userRef($table, 'pharmacist_id');
            $userRef($table, 'cashier_id');
            $table->string('approval_mode', 10)->default('none');
            $table->jsonb('flags')->default('[]');
            $table->string('review_status', 10)->default('none');
            $userRef($table, 'reviewed_by');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampTz('dispensed_at');
            $table->timestampsTz();

            $table->unique('sale_line_id');
            $table->index('sale_id');
            $table->index('branch_id');
            $table->index(['location_id', 'dispensed_at']);
            $table->index('item_id');
            $table->index('customer_id');
            $table->index('prescription_id');
            $table->index('company_id');
            $table->index('pharmacist_id');
            $table->index('cashier_id');
            $table->index(['review_status', 'dispensed_at']);
        });

        DB::statement("alter table pharmacy_dispenses add constraint pharmacy_dispenses_values_check check (approval_mode in ('self', 'override', 'none') and review_status in ('none', 'open', 'reviewed'))");
        Rls::enable('pharmacy_dispenses');

        // PHA-18: the controlled-drug register and its running balance per item and place.
        Schema::create('pharmacy_register_heads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->bigInteger('last_no')->default(0);
            $table->decimal('balance', 20, 6)->default(0);
            $table->timestampsTz();

            $table->unique(['location_id', 'item_id']);
            $table->index('item_id');
            $table->index('company_id');
        });
        Rls::enable('pharmacy_register_heads');

        Schema::create('pharmacy_register_entries', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->bigInteger('entry_no');
            $table->string('kind', 16);
            $table->decimal('qty', 20, 6);
            $table->decimal('balance', 20, 6);
            $table->uuid('lot_id')->nullable();
            $table->string('batch_number', 60)->nullable();
            $table->date('expires_on')->nullable();
            $table->uuid('prescription_id')->nullable();
            $table->string('prescriber_name', 150)->nullable();
            $table->string('prescriber_licence', 60)->nullable();
            $table->string('patient_name', 150)->nullable();
            $userRef($table, 'pharmacist_id');
            $userRef($table, 'recorded_by');
            $table->string('source_type', 40);
            $table->uuid('source_id')->nullable();
            $table->uuid('source_line_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['location_id', 'item_id', 'entry_no']);
            $table->index(['location_id', 'item_id', 'occurred_at']);
            $table->index('item_id');
            $table->index('company_id');
            $table->index('pharmacist_id');
        });

        DB::statement("alter table pharmacy_register_entries add constraint pharmacy_register_entries_kind_check check (kind in ('receipt', 'dispense', 'return', 'transfer_out', 'transfer_in', 'write_off', 'adjustment') and qty <> 0 and entry_no > 0)");
        DB::statement("create unique index pharmacy_register_entries_once on pharmacy_register_entries (location_id, item_id, kind, source_type, source_id, coalesce(source_line_id, '00000000-0000-0000-0000-000000000000'::uuid), coalesce(lot_id, '00000000-0000-0000-0000-000000000000'::uuid)) where source_id is not null");
        Rls::enable('pharmacy_register_entries');

        // PHA-10: which alerts were already sent.
        Schema::create('pharmacy_expiry_notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('lot_id')->constrained('pharmacy_lots')->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('stage', 10);
            $table->timestampTz('notified_at');

            $table->unique(['lot_id', 'location_id', 'stage']);
            $table->index('location_id');
        });

        DB::statement("alter table pharmacy_expiry_notices add constraint pharmacy_expiry_notices_stage_check check (stage in ('near', 'expired'))");
        Rls::enable('pharmacy_expiry_notices');

        // The lot ledger and the register are append-only.
        DB::unprepared(<<<'SQL'
            create or replace function pharmacy_append_only() returns trigger
            language plpgsql as $$
            begin
                raise exception '% is append-only', tg_table_name using errcode = 'insufficient_privilege';
            end;
            $$
            SQL);

        foreach (self::LEDGERS as $table) {
            DB::statement("create trigger {$table}_append_only before update or delete on {$table} for each row execute function pharmacy_append_only()");
        }
    }

    public function down(): void
    {
        foreach ([
            'pharmacy_expiry_notices', 'pharmacy_register_entries', 'pharmacy_register_heads', 'pharmacy_dispenses', 'pharmacy_prescriptions',
            'pharmacy_write_off_lines', 'pharmacy_write_offs', 'pharmacy_receipt_lines', 'pharmacy_receipts', 'pharmacy_lot_movements',
            'pharmacy_lot_balances', 'pharmacy_lots', 'pharmacy_recalls', 'pharmacy_item_profiles', 'pharmacy_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        DB::unprepared('drop function if exists pharmacy_append_only()');
    }
};
