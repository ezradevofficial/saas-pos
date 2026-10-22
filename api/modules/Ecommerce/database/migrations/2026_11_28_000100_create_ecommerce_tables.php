<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// E-commerce and delivery module (docs/modules/ecommerce.md). Every table is
// the tenant's, under forced row-level security (TEN-01, ECOM-38). Money is
// bigint minor units next to its currency; base amounts in the company's
// base currency with the rate used in an fx snapshot (numeric(18,8),
// ADR 003, CUR-04); quantities numeric(20,6). Users are referenced by
// (tenant_id, id), so a row never points at another tenant's user. Records
// are archived or cancelled, never deleted (TEN-06). The public storefront
// (ECOM-06) and the WhatsApp webhook (ECOM-24) find their tenant through
// security-definer functions that return nothing but the tenant id
// (ADR 002); `ecommerce:expire` finds the tenants with expired
// reservations the same way (ECOM-14).
return new class extends Migration
{
    /**
     * Dropped in this order by down(): every table before the tables it
     * points at (Phase 8 review L10: payments and deliveries before cash-ups,
     * cash-ups before riders). Orders and conversations point at each other;
     * down() drops both foreign keys first.
     */
    private const TABLES = [
        'ecom_messages', 'ecom_delivery_updates', 'ecom_deliveries', 'ecom_payments', 'ecom_cashups', 'ecom_riders',
        'ecom_order_events', 'ecom_reservations', 'ecom_order_lines', 'ecom_conversations', 'ecom_orders', 'ecom_addresses',
        'ecom_customer_sessions', 'ecom_customer_codes', 'ecom_customers', 'ecom_zones', 'ecom_products', 'ecom_stores',
    ];

    public function up(): void
    {
        $userRef = function (Blueprint $table, string $column, bool $nullable = true): void {
            $definition = $table->uuid($column);

            if ($nullable) {
                $definition->nullable();
            }

            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        };
        $currency = function (Blueprint $table, string $column, bool $nullable = false): void {
            $definition = $table->char($column, 3);

            if ($nullable) {
                $definition->nullable();
            }

            $table->foreign($column)->references('code')->on('currencies')->restrictOnDelete();
        };
        $check = fn (string $table, string $name, string $condition) => DB::statement("alter table {$table} add constraint {$table}_{$name}_check check ({$condition})");
        $in = fn (string $column, array $values) => "{$column} in ('".implode("', '", $values)."')";

        // ECOM-01: stores.
        Schema::create('ecom_stores', function (Blueprint $table) use ($currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('handle', 60);
            $table->string('name', 120);
            $table->string('status', 10)->default('draft');
            $currency($table, 'currency');
            $table->foreignUuid('price_list_id')->constrained('price_lists')->restrictOnDelete();
            $table->boolean('delivery_enabled')->default(true);
            $table->boolean('pickup_enabled')->default(false);
            $table->jsonb('pickup_location_ids')->default('[]');
            $table->bigInteger('min_order_minor')->default(0);
            $table->integer('reservation_minutes')->default(30);
            $table->decimal('safety_buffer', 20, 6)->default(0);
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->string('whatsapp_number', 32)->nullable();
            $table->char('whatsapp_token_hash', 64)->nullable()->unique();
            $table->foreignUuid('delivery_tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->jsonb('payment_methods')->default('[]');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'handle']);
            $table->index(['tenant_id', 'company_id']);
        });
        $check('ecom_stores', 'status', $in('status', ['draft', 'live', 'paused']));
        $check('ecom_stores', 'values', "min_order_minor >= 0 and reservation_minutes between 5 and 1440 and safety_buffer >= 0 and handle ~ '^[a-z0-9]([a-z0-9-]{0,58}[a-z0-9])?$' and jsonb_typeof(payment_methods) = 'array' and jsonb_typeof(pickup_location_ids) = 'array'");
        Rls::enable('ecom_stores');

        // ECOM-02: the catalogue.
        Schema::create('ecom_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('store_id')->constrained('ecom_stores')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->boolean('published')->default(false);
            $table->boolean('featured')->default(false);
            $table->integer('position')->default(0);
            $table->boolean('track_stock')->default(true);
            $table->boolean('in_stock')->default(true);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['store_id', 'item_id']);
            $table->unique(['store_id', 'slug']);
            $table->index(['tenant_id', 'item_id']);
        });
        $check('ecom_products', 'slug', "slug ~ '^[a-z0-9]([a-z0-9-]{0,118}[a-z0-9])?$'");
        Rls::enable('ecom_products');

        // ECOM-10: delivery zones and fees.
        Schema::create('ecom_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('store_id')->constrained('ecom_stores')->restrictOnDelete();
            $table->string('name', 120);
            $table->jsonb('areas')->default('[]');
            $table->bigInteger('fee_minor')->default(0);
            $table->bigInteger('free_over_minor')->nullable();
            $table->bigInteger('min_order_minor')->nullable();
            $table->string('eta', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->integer('position')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'store_id']);
        });
        $check('ecom_zones', 'values', "fee_minor >= 0 and (free_over_minor is null or free_over_minor >= 0) and (min_order_minor is null or min_order_minor >= 0) and jsonb_typeof(areas) = 'array'");
        Rls::enable('ecom_zones');

        // ECOM-08: online customers, their sign-in codes and sessions, saved addresses.
        Schema::create('ecom_customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('phone', 32)->nullable();
            $table->string('email', 190)->nullable();
            $table->boolean('consent')->default(false);
            $table->timestampTz('last_signed_in_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'party_id']);
        });
        DB::statement('create unique index ecom_customers_phone_unique on ecom_customers (company_id, phone) where phone is not null');
        DB::statement('create unique index ecom_customers_email_unique on ecom_customers (company_id, email) where email is not null');
        Rls::enable('ecom_customers');

        Schema::create('ecom_customer_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('store_id')->constrained('ecom_stores')->restrictOnDelete();
            $table->string('contact', 190);
            $table->string('channel', 5);
            $table->char('code_hash', 64);
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'store_id', 'contact']);
        });
        $check('ecom_customer_codes', 'channel', $in('channel', ['sms', 'email']));
        Rls::enable('ecom_customer_codes');

        Schema::create('ecom_customer_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('customer_id')->constrained('ecom_customers')->restrictOnDelete();
            $table->foreignUuid('store_id')->constrained('ecom_stores')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'customer_id']);
        });
        Rls::enable('ecom_customer_sessions');

        Schema::create('ecom_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('customer_id')->constrained('ecom_customers')->restrictOnDelete();
            $table->foreignUuid('zone_id')->nullable()->constrained('ecom_zones')->restrictOnDelete();
            $table->string('area', 120)->nullable();
            $table->string('line', 300);
            $table->string('notes', 300)->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'customer_id']);
        });
        Rls::enable('ecom_addresses');

        // ECOM-26: riders (before orders: a delivery names one).
        Schema::create('ecom_riders', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $userRef($table, 'user_id', nullable: false);
            $table->uuid('employee_id')->nullable();
            $table->string('name', 160);
            $table->string('phone', 32)->nullable();
            $table->string('vehicle', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });
        Rls::enable('ecom_riders');

        // ECOM-30: rider cash-ups (before deliveries and payments: they name one).
        Schema::create('ecom_cashups', function (Blueprint $table) use ($userRef, $currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('rider_id')->constrained('ecom_riders')->restrictOnDelete();
            $currency($table, 'currency');
            $table->bigInteger('expected_minor');
            $table->bigInteger('counted_minor');
            $table->bigInteger('variance_minor');
            $table->integer('deliveries');
            $table->string('note', 500)->nullable();
            $userRef($table, 'closed_by', nullable: false);
            $table->timestampTz('closed_at');
            $table->timestampsTz();

            $table->index(['tenant_id', 'rider_id']);
        });
        $check('ecom_cashups', 'values', 'expected_minor >= 0 and counted_minor >= 0 and variance_minor = counted_minor - expected_minor and deliveries >= 1');
        Rls::enable('ecom_cashups');

        // ECOM-24: WhatsApp conversations (before orders: an order may come from one).
        Schema::create('ecom_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('store_id')->constrained('ecom_stores')->restrictOnDelete();
            $table->string('phone', 32);
            $table->string('name', 160)->nullable();
            $table->string('status', 10)->default('open');
            $table->uuid('order_id')->nullable();
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampsTz();

            $table->unique(['store_id', 'phone']);
        });
        $check('ecom_conversations', 'status', $in('status', ['open', 'converted', 'closed']));
        Rls::enable('ecom_conversations');

        Schema::create('ecom_messages', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('conversation_id')->constrained('ecom_conversations')->restrictOnDelete();
            $table->foreignUuid('store_id')->constrained('ecom_stores')->restrictOnDelete();
            $table->string('direction', 3);
            $table->text('body');
            $table->string('provider_message_id', 120)->nullable();
            $userRef($table, 'sent_by');
            $table->string('status', 10)->default('received');
            $table->timestampTz('at');
            $table->timestampsTz();

            $table->index(['tenant_id', 'conversation_id', 'at']);
        });
        DB::statement('create unique index ecom_messages_provider_unique on ecom_messages (store_id, provider_message_id) where provider_message_id is not null');
        $check('ecom_messages', 'direction', $in('direction', ['in', 'out']));
        $check('ecom_messages', 'status', $in('status', ['received', 'sent', 'failed']));
        Rls::enable('ecom_messages');

        // ECOM-16 to ECOM-22: orders.
        Schema::create('ecom_orders', function (Blueprint $table) use ($userRef, $currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('store_id')->constrained('ecom_stores')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('number', 80);
            $table->string('channel', 10);
            $table->foreignUuid('customer_id')->nullable()->constrained('ecom_customers')->restrictOnDelete();
            $table->foreignUuid('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('contact_name', 160);
            $table->string('contact_phone', 32);
            $table->string('contact_email', 190)->nullable();
            $table->string('locale', 2)->default('en');
            $table->string('fulfilment', 10);
            $table->foreignUuid('zone_id')->nullable()->constrained('ecom_zones')->restrictOnDelete();
            $table->foreignUuid('pickup_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->jsonb('address')->nullable();
            $table->string('status', 20);
            $table->string('payment_status', 15);
            $table->string('payment_kind', 10);
            $currency($table, 'currency');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('delivery_fee_minor');
            $table->bigInteger('delivery_tax_minor');
            $table->bigInteger('total_minor');
            $table->bigInteger('loyalty_paid_minor')->default(0);
            $table->bigInteger('due_minor');
            $currency($table, 'base_currency');
            $table->fxSnapshot('fx');
            $table->bigInteger('base_total_minor');
            $table->string('coupon', 40)->nullable();
            $table->jsonb('promotions')->default('[]');
            $table->string('idempotency_key', 100)->nullable();
            $table->char('body_hash', 64)->nullable();
            $table->char('tracking_token_hash', 64)->unique();
            // ECOM-20: the tracking link's token, encrypted with the application key, for later messages (never exported).
            $table->text('tracking_token_secret');
            $table->char('delivery_code_hash', 64)->nullable();
            $table->timestampTz('reserved_until')->nullable();
            $table->timestampTz('placed_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('picking_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $userRef($table, 'cancelled_by');
            $table->string('cancel_reason', 500)->nullable();
            $table->timestampTz('returned_at')->nullable();
            $userRef($table, 'returned_by');
            $table->string('return_reason', 500)->nullable();
            $table->boolean('return_fee')->default(false);
            $table->string('refund_reference', 120)->nullable();
            $table->foreignUuid('conversation_id')->nullable()->constrained('ecom_conversations')->restrictOnDelete();
            $userRef($table, 'created_by');
            $table->string('notes', 1000)->nullable();
            $table->jsonb('flags')->default('[]');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'location_id', 'status']);
            $table->index(['tenant_id', 'party_id']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'status', 'reserved_until']);
        });
        DB::statement('create unique index ecom_orders_idempotency_unique on ecom_orders (store_id, idempotency_key) where idempotency_key is not null');
        $check('ecom_orders', 'channel', $in('channel', ['web', 'phone', 'whatsapp']));
        $check('ecom_orders', 'locale', $in('locale', ['en', 'fr']));
        $check('ecom_orders', 'fulfilment', $in('fulfilment', ['delivery', 'pickup']));
        $check('ecom_orders', 'status', $in('status', ['placed', 'accepted', 'picking', 'ready', 'out_for_delivery', 'delivered', 'cancelled', 'returned']));
        $check('ecom_orders', 'payment_status', $in('payment_status', ['pending', 'paid', 'cod', 'cod_collected', 'failed', 'refund_due', 'refunded']));
        $check('ecom_orders', 'payment_kind', $in('payment_kind', ['mpesa', 'card', 'cod', 'loyalty']));
        $check('ecom_orders', 'amounts', 'subtotal_minor >= 0 and discount_minor >= 0 and tax_minor >= 0 and delivery_fee_minor >= 0 and delivery_tax_minor >= 0 and total_minor >= 0 and loyalty_paid_minor >= 0 and due_minor >= 0 and due_minor + loyalty_paid_minor = total_minor');
        Rls::enable('ecom_orders');
        DB::statement('alter table ecom_conversations add constraint ecom_conversations_order_fk foreign key (order_id) references ecom_orders (id) on delete restrict');

        Schema::create('ecom_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('order_id')->constrained('ecom_orders')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('product_id')->nullable()->constrained('ecom_products')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->string('name', 200);
            $table->decimal('qty', 20, 6);
            $table->decimal('qty_base', 20, 6);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('gross_minor');
            $table->bigInteger('discount_minor');
            $table->bigInteger('net_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->foreignUuid('tax_code_id')->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_rate', 9, 4)->nullable();
            $table->boolean('tax_inclusive');
            $table->jsonb('promotion')->nullable();
            $table->timestampsTz();

            $table->unique(['order_id', 'line_no']);
            $table->index(['tenant_id', 'item_id']);
        });
        $check('ecom_order_lines', 'values', 'qty > 0 and qty_base > 0 and unit_price_minor >= 0 and gross_minor >= 0 and discount_minor >= 0 and tax_minor >= 0 and total_minor >= 0');
        Rls::enable('ecom_order_lines');

        // ECOM-14: stock reservations.
        Schema::create('ecom_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('order_id')->constrained('ecom_orders')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('qty_base', 20, 6);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id', 'item_id']);
            $table->index(['tenant_id', 'order_id']);
        });
        $check('ecom_reservations', 'values', 'qty_base > 0 and (released_at is null or consumed_at is null)');
        Rls::enable('ecom_reservations');

        // ECOM-15, ECOM-30: payments of an order.
        Schema::create('ecom_payments', function (Blueprint $table) use ($userRef, $currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('order_id')->constrained('ecom_orders')->restrictOnDelete();
            $table->string('kind', 10);
            $table->foreignUuid('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->uuid('payment_intent_id')->nullable();
            $currency($table, 'currency');
            $table->bigInteger('amount_minor');
            // refund_due / refunded_excess: money beyond the order (a second settlement, a wrong amount), held for refund and never posted.
            $table->string('status', 15);
            $table->string('provider_reference', 120)->nullable();
            $table->jsonb('detail')->nullable();
            $userRef($table, 'collected_by');
            $table->timestampTz('collected_at')->nullable();
            $table->foreignUuid('cashup_id')->nullable()->constrained('ecom_cashups')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'payment_intent_id']);
        });
        $check('ecom_payments', 'kind', $in('kind', ['mpesa', 'card', 'cod', 'points', 'gift_card']));
        $check('ecom_payments', 'status', $in('status', ['pending', 'succeeded', 'failed', 'collected', 'refunded', 'reversed', 'refund_due', 'refunded_excess']));
        $check('ecom_payments', 'amount', 'amount_minor >= 0');
        Rls::enable('ecom_payments');

        // ECOM-16: an order's history.
        Schema::create('ecom_order_events', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('order_id')->constrained('ecom_orders')->restrictOnDelete();
            $table->string('from', 20)->nullable();
            $table->string('to', 20);
            $table->string('payment_status', 15)->nullable();
            $table->string('note', 500)->nullable();
            $userRef($table, 'user_id');
            $table->timestampTz('at');
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id', 'at']);
        });
        Rls::enable('ecom_order_events');

        // ECOM-27 to ECOM-30: deliveries.
        Schema::create('ecom_deliveries', function (Blueprint $table) use ($currency, $userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('order_id')->unique()->constrained('ecom_orders')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('rider_id')->nullable()->constrained('ecom_riders')->restrictOnDelete();
            $table->string('status', 12)->default('unassigned');
            $table->timestampTz('assigned_at')->nullable();
            $table->timestampTz('picked_up_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('proof_kind', 5)->nullable();
            $table->string('proof_photo_path', 255)->nullable();
            // ECOM-29: wrong delivery codes, counted under the delivery's row lock; locked at five (staff confirm).
            $table->smallInteger('code_attempts')->default(0);
            $table->boolean('code_locked')->default(false);
            $table->decimal('last_latitude', 9, 6)->nullable();
            $table->decimal('last_longitude', 9, 6)->nullable();
            $table->timestampTz('located_at')->nullable();
            $currency($table, 'currency');
            $table->bigInteger('cod_due_minor')->default(0);
            $table->bigInteger('cod_collected_minor')->nullable();
            $table->timestampTz('collected_at')->nullable();
            // ECOM-30 (decision 11): who took the cash: the rider, or the staff member who confirmed the delivery.
            $userRef($table, 'collected_by');
            $table->foreignUuid('cashup_id')->nullable()->constrained('ecom_cashups')->restrictOnDelete();
            $table->jsonb('flags')->default('[]');
            $table->timestampsTz();

            $table->index(['tenant_id', 'rider_id', 'status']);
            $table->index(['tenant_id', 'location_id', 'status']);
        });
        $check('ecom_deliveries', 'status', $in('status', ['unassigned', 'assigned', 'picked_up', 'delivered', 'failed']));
        $check('ecom_deliveries', 'proof', $in('proof_kind', ['code', 'photo']).' or proof_kind is null');
        $check('ecom_deliveries', 'cod', 'cod_due_minor >= 0 and (cod_collected_minor is null or cod_collected_minor >= 0)');
        Rls::enable('ecom_deliveries');

        Schema::create('ecom_delivery_updates', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('delivery_id')->constrained('ecom_deliveries')->restrictOnDelete();
            $table->foreignUuid('rider_id')->constrained('ecom_riders')->restrictOnDelete();
            $userRef($table, 'user_id', nullable: false);
            $table->string('kind', 10);
            $table->string('status', 12)->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->bigInteger('collected_minor')->nullable();
            $table->string('note', 500)->nullable();
            $table->char('payload_hash', 64);
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->string('outcome', 10);
            $table->string('error', 40)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'delivery_id', 'occurred_at']);
        });
        $check('ecom_delivery_updates', 'kind', $in('kind', ['status', 'location']));
        $check('ecom_delivery_updates', 'outcome', $in('outcome', ['applied', 'rejected']));
        Rls::enable('ecom_delivery_updates');

        // ECOM-06: the tenant of a live store, by the storefront's host (its slug under
        // the base domain, or a verified custom domain of an active tenant) and the
        // store's handle; nothing but the id (ADR 002).
        DB::unprepared(<<<'SQL'
            create or replace function public.ecom_store_tenant(p_slug text, p_host text, p_handle text) returns uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select s.tenant_id from public.ecom_stores s
                join public.tenants t on t.id = s.tenant_id and t.status = 'active'
                where s.handle = lower(p_handle) and s.status = 'live' and s.archived_at is null
                  and (
                    (p_slug is not null and t.slug = lower(p_slug))
                    or (p_host is not null and exists (
                        select 1 from public.tenant_domains d
                        where d.tenant_id = t.id and d.host = lower(p_host) and d.status = 'verified' and d.archived_at is null
                    ))
                  )
                limit 1
            $$;

            revoke all on function public.ecom_store_tenant(text, text, text) from public;
            SQL);

        // ECOM-24: the tenant of a store's WhatsApp webhook token hash (archived stores included; the controller refuses them).
        DB::unprepared(<<<'SQL'
            create or replace function public.ecom_whatsapp_tenant(p_token_hash text) returns uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select s.tenant_id from public.ecom_stores s
                where s.whatsapp_token_hash = p_token_hash
                limit 1
            $$;

            revoke all on function public.ecom_whatsapp_tenant(text) from public;
            SQL);

        // ECOM-14: the tenants with an unpaid order whose reservation expired at p_at, for `ecommerce:expire`.
        DB::unprepared(<<<'SQL'
            create or replace function public.app_tenants_with_expired_ecom_reservations(p_at timestamptz) returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $fn$
                select distinct o.tenant_id from public.ecom_orders o
                where o.status = 'placed' and o.payment_status in ('pending', 'failed') and o.reserved_until is not null and o.reserved_until <= p_at
                order by 1
            $fn$;

            revoke all on function public.app_tenants_with_expired_ecom_reservations(timestamptz) from public;
            SQL);

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("grant execute on function public.ecom_store_tenant(text, text, text) to {$runtimeRole}");
        DB::statement("grant execute on function public.ecom_whatsapp_tenant(text) to {$runtimeRole}");
        DB::statement("grant execute on function public.app_tenants_with_expired_ecom_reservations(timestamptz) to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.ecom_store_tenant(text, text, text)');
        DB::statement('drop function if exists public.ecom_whatsapp_tenant(text)');
        DB::statement('drop function if exists public.app_tenants_with_expired_ecom_reservations(timestamptz)');
        DB::statement('alter table if exists ecom_conversations drop constraint if exists ecom_conversations_order_fk');
        // Phase 8 review L10: orders point back at their WhatsApp conversation, which is dropped first.
        DB::statement('alter table if exists ecom_orders drop constraint if exists ecom_orders_conversation_id_foreign');

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
