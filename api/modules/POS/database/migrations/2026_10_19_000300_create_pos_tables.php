<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// POS module (docs/modules/pos.md). Every table is the tenant's, under
// forced row-level security (TEN-01). Records made on a till keep the
// UUID v7 the device gave them (ADR 004): uploads are idempotent by id.
// Amounts are bigint minor units of the row's currency (ADR 003); rates
// numeric(18,8) through the fxSnapshot macro (CUR-04). Users are
// referenced by (tenant_id, id), so a row never points at another
// tenant's user.
return new class extends Migration
{
    public function up(): void
    {
        // NUM-02: non-overlapping ranges per counter need a GiST exclusion
        // constraint over (uuid, int8range); btree_gist is a trusted
        // extension the database owner may create.
        DB::statement('create extension if not exists btree_gist with schema public');

        $userRef = function (Blueprint $table, string $column, bool $nullable = false): void {
            $definition = $table->uuid($column);

            if ($nullable) {
                $definition->nullable();
            }

            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        };

        $place = function (Blueprint $table): void {
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
        };

        // NUM-02: blocks of a document type's counter given to one device.
        Schema::create('pos_number_ranges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 60);
            $table->foreignUuid('number_sequence_id')->constrained()->restrictOnDelete();
            $table->string('period', 4);
            // The format's pattern with place codes (and a yearly format's year) filled in.
            $table->string('pattern', 80);
            $table->bigInteger('range_from');
            $table->bigInteger('range_to');
            // The first number not known to be used (device reports and uploads).
            $table->bigInteger('next_value');
            $table->string('status', 10);
            $table->timestampTz('allocated_at');
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();

            $table->index(['device_id', 'document_type', 'status']);
        });

        DB::statement("alter table pos_number_ranges add constraint pos_number_ranges_status_check check (status in ('active', 'exhausted', 'retired'))");
        DB::statement('alter table pos_number_ranges add constraint pos_number_ranges_bounds_check check (range_from >= 1 and range_from <= range_to and next_value between range_from and range_to + 1)');
        DB::statement("alter table pos_number_ranges add constraint pos_number_ranges_no_overlap exclude using gist (number_sequence_id with =, int8range(range_from, range_to, '[]') with &&)");
        Rls::enable('pos_number_ranges');

        // POS-04: a cashier's shift on one device.
        Schema::create('pos_shifts', function (Blueprint $table) use ($place, $userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->string('status', 10);
            $userRef($table, 'opened_by');
            $table->timestampTz('opened_at', 6);
            $userRef($table, 'closed_by', nullable: true);
            $table->timestampTz('closed_at', 6)->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('closed_received_at')->nullable();
            $table->timestampsTz();

            $table->index(['location_id', 'opened_at']);
            $table->index(['company_id', 'opened_at']);
        });

        DB::statement("alter table pos_shifts add constraint pos_shifts_status_check check (status in ('open', 'closed'))");
        DB::statement("alter table pos_shifts add constraint pos_shifts_closed_check check ((status = 'closed') = (closed_at is not null) and (closed_at is null) = (closed_by is null))");
        DB::statement("create unique index pos_shifts_one_open_per_device on pos_shifts (device_id) where status = 'open'");
        Rls::enable('pos_shifts');

        // POS-04: cash per currency: opening float, counted, expected, variance.
        Schema::create('pos_shift_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('shift_id')->constrained('pos_shifts')->restrictOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('opening_minor');
            $table->bigInteger('counted_minor')->nullable();
            $table->bigInteger('expected_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['shift_id', 'currency']);
        });

        DB::statement('alter table pos_shift_balances add constraint pos_shift_balances_amounts_check check (opening_minor >= 0 and (counted_minor is null or counted_minor >= 0))');
        Rls::enable('pos_shift_balances');

        // POS-01, POS-03, POS-06, POS-08, POS-09: a completed sale.
        Schema::create('pos_sales', function (Blueprint $table) use ($place, $userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->foreignUuid('shift_id')->constrained('pos_shifts')->restrictOnDelete();
            $userRef($table, 'cashier_id');
            $table->foreignUuid('customer_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('number_range_id')->constrained('pos_number_ranges')->restrictOnDelete();
            $table->bigInteger('receipt_seq');
            $table->string('receipt_number', 80);
            $table->string('status', 10);
            $table->char('currency', 3);
            $table->foreignUuid('price_list_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->bigInteger('paid_minor');
            $table->bigInteger('change_minor');
            $table->char('change_currency', 3);
            $table->bigInteger('rounding_minor');
            // CUR-04: the company's base currency and the rate used to reach it.
            $table->char('base_currency', 3);
            $table->bigInteger('base_total_minor');
            $table->bigInteger('base_tax_minor');
            $table->fxSnapshot('fx');
            $table->timestampTz('sold_at', 6);
            $table->timestampTz('received_at');
            $table->boolean('offline')->default(false);
            // POS-09: what the server noticed but did not refuse (device wins).
            $table->jsonb('flags')->default('[]');
            // M3: a flagged sale acknowledged in the back office.
            $table->timestampTz('reviewed_at')->nullable();
            $userRef($table, 'reviewed_by', nullable: true);
            // ADR 004: the upload's content, so a resend with other content under the same id is refused.
            $table->char('payload_hash', 64);
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();

            foreach (['currency', 'change_currency', 'base_currency'] as $column) {
                $table->foreign($column)->references('code')->on('currencies')->restrictOnDelete();
            }
            $table->unique(['number_range_id', 'receipt_seq']);
            $table->index(['company_id', 'sold_at']);
            $table->index(['location_id', 'sold_at']);
            $table->index('shift_id');
            // NUM-01: a printed receipt number is never stored twice in a tenant.
            $table->unique(['tenant_id', 'receipt_number']);
        });

        DB::statement("alter table pos_sales add constraint pos_sales_status_check check (status in ('completed', 'voided'))");
        DB::statement('alter table pos_sales add constraint pos_sales_amounts_check check (subtotal_minor >= 0 and discount_minor >= 0 and tax_minor >= 0 and total_minor >= 0 and paid_minor >= total_minor and change_minor >= 0 and base_total_minor >= 0)');
        DB::statement("alter table pos_sales add constraint pos_sales_flags_check check (jsonb_typeof(flags) = 'array')");
        Rls::enable('pos_sales');

        Schema::create('pos_sale_lines', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('sale_id')->constrained('pos_sales')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            // The name printed on the receipt, as sold.
            $table->string('item_name');
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('qty', 18, 6);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('list_price_minor')->nullable();
            $table->foreignUuid('price_list_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('tax_inclusive');
            $table->bigInteger('discount_minor');
            $table->foreignUuid('tax_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('tax_rate', 9, 4)->nullable();
            $table->bigInteger('net_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            // POS-07, AUTH-08: who allowed a price override or a discount above the cashier's limit.
            $userRef($table, 'price_override_by', nullable: true);
            $userRef($table, 'discount_override_by', nullable: true);
            $table->decimal('refunded_qty', 18, 6)->default(0);
            $table->timestampsTz();

            $table->unique(['sale_id', 'line_no']);
            $table->index('item_id');
        });

        DB::statement('alter table pos_sale_lines add constraint pos_sale_lines_amounts_check check (qty > 0 and unit_price_minor >= 0 and discount_minor >= 0 and net_minor >= 0 and tax_minor >= 0 and total_minor >= 0 and refunded_qty >= 0 and refunded_qty <= qty)');
        Rls::enable('pos_sale_lines');

        Schema::create('pos_sale_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('sale_id')->constrained('pos_sales')->restrictOnDelete();
            $table->foreignUuid('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('method_type', 20);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('amount_in_sale_minor');
            // CUR-04, CUR-09: the rate the till used for this tender.
            $table->fxSnapshot('fx');
            $table->string('provider_reference', 100)->nullable();
            $table->string('status', 20);
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index('sale_id');
        });

        DB::statement("alter table pos_sale_payments add constraint pos_sale_payments_status_check check (status in ('confirmed', 'pending'))");
        DB::statement('alter table pos_sale_payments add constraint pos_sale_payments_amounts_check check (amount_minor > 0 and amount_in_sale_minor >= 0)');
        Rls::enable('pos_sale_payments');

        // POS-04: cash paid into or out of the drawer, with a reason.
        Schema::create('pos_cash_movements', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('shift_id')->constrained('pos_shifts')->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->text('reason');
            $userRef($table, 'user_id');
            $userRef($table, 'approved_by', nullable: true);
            $table->boolean('override_verified')->default(false);
            // POS-05, AUTH-08: applied, or held for review when who allowed it can't be proven yet.
            $table->string('status', 10);
            $table->jsonb('flags')->default('[]');
            $table->char('payload_hash', 64);
            $table->timestampTz('decided_at')->nullable();
            $userRef($table, 'decided_by', nullable: true);
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('received_at');
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index('shift_id');
            $table->index(['tenant_id', 'status']);
        });

        DB::statement("alter table pos_cash_movements add constraint pos_cash_movements_status_check check (status in ('applied', 'held', 'rejected'))");
        DB::statement("alter table pos_cash_movements add constraint pos_cash_movements_kind_check check (kind in ('pay_in', 'pay_out'))");
        DB::statement('alter table pos_cash_movements add constraint pos_cash_movements_amount_check check (amount_minor > 0)');
        Rls::enable('pos_cash_movements');

        // POS-05: a whole sale cancelled (one void per sale).
        Schema::create('pos_sale_voids', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('sale_id')->constrained('pos_sales')->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $userRef($table, 'voided_by');
            $userRef($table, 'approved_by', nullable: true);
            $table->boolean('override_verified')->default(false);
            // POS-05, AUTH-08: applied, or held for review when who allowed it can't be proven yet.
            $table->string('status', 10);
            $table->jsonb('flags')->default('[]');
            $table->char('payload_hash', 64);
            $table->timestampTz('decided_at')->nullable();
            $userRef($table, 'decided_by', nullable: true);
            $table->text('reason');
            $table->timestampTz('voided_at', 6);
            $table->timestampTz('received_at');
            $table->timestampsTz();
        });

        // One void applied or waiting per sale; a rejected one does not count.
        DB::statement("create unique index pos_sale_voids_one_per_sale on pos_sale_voids (sale_id) where status <> 'rejected'");
        DB::statement("alter table pos_sale_voids add constraint pos_sale_voids_status_check check (status in ('applied', 'held', 'rejected'))");
        Rls::enable('pos_sale_voids');

        // POS-05: some lines or quantities of a sale given back and refunded.
        Schema::create('pos_refunds', function (Blueprint $table) use ($place, $userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('sale_id')->constrained('pos_sales')->restrictOnDelete();
            $place($table);
            $table->foreignUuid('shift_id')->constrained('pos_shifts')->restrictOnDelete();
            $userRef($table, 'cashier_id');
            $userRef($table, 'approved_by', nullable: true);
            $table->boolean('override_verified')->default(false);
            // POS-05, AUTH-08: applied, or held for review when who allowed it can't be proven yet.
            $table->string('status', 10);
            $table->jsonb('flags')->default('[]');
            $table->char('payload_hash', 64);
            $table->timestampTz('decided_at')->nullable();
            $userRef($table, 'decided_by', nullable: true);
            $table->foreignUuid('number_range_id')->constrained('pos_number_ranges')->restrictOnDelete();
            $table->bigInteger('receipt_seq');
            $table->string('receipt_number', 80);
            $table->text('reason');
            $table->char('currency', 3);
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->char('base_currency', 3);
            $table->bigInteger('base_total_minor');
            $table->fxSnapshot('fx');
            $table->timestampTz('refunded_at', 6);
            $table->timestampTz('received_at');
            $table->timestampsTz();

            foreach (['currency', 'base_currency'] as $column) {
                $table->foreign($column)->references('code')->on('currencies')->restrictOnDelete();
            }
            $table->unique(['number_range_id', 'receipt_seq']);
            $table->unique(['tenant_id', 'receipt_number']);
            $table->index('sale_id');
            $table->index('shift_id');
            $table->index(['tenant_id', 'status']);
        });

        DB::statement("alter table pos_refunds add constraint pos_refunds_status_check check (status in ('applied', 'held', 'rejected'))");
        DB::statement('alter table pos_refunds add constraint pos_refunds_amounts_check check (tax_minor >= 0 and total_minor > 0 and base_total_minor >= 0)');
        Rls::enable('pos_refunds');

        Schema::create('pos_refund_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('refund_id')->constrained('pos_refunds')->restrictOnDelete();
            $table->foreignUuid('sale_line_id')->constrained('pos_sale_lines')->restrictOnDelete();
            $table->decimal('qty', 18, 6);
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->timestampsTz();

            $table->index('refund_id');
            $table->index('sale_line_id');
        });

        DB::statement('alter table pos_refund_lines add constraint pos_refund_lines_amounts_check check (qty > 0 and tax_minor >= 0 and total_minor >= 0)');
        Rls::enable('pos_refund_lines');

        Schema::create('pos_refund_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('refund_id')->constrained('pos_refunds')->restrictOnDelete();
            $table->foreignUuid('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('method_type', 20);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('amount_in_sale_minor');
            $table->fxSnapshot('fx');
            $table->string('provider_reference', 100)->nullable();
            $table->string('status', 20);
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index('refund_id');
        });

        DB::statement("alter table pos_refund_payments add constraint pos_refund_payments_status_check check (status in ('confirmed', 'pending'))");
        DB::statement('alter table pos_refund_payments add constraint pos_refund_payments_amounts_check check (amount_minor > 0 and amount_in_sale_minor >= 0)');
        Rls::enable('pos_refund_payments');
    }

    public function down(): void
    {
        foreach (['pos_refund_payments', 'pos_refund_lines', 'pos_refunds', 'pos_sale_voids', 'pos_cash_movements', 'pos_sale_payments', 'pos_sale_lines', 'pos_sales', 'pos_shift_balances', 'pos_shifts', 'pos_number_ranges'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
