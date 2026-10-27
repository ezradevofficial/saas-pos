<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Restaurant module (docs/modules/restaurant.md). Every table is the
// tenant's, under forced row-level security (TEN-01). Money is bigint minor
// units with its currency (ADR 003). Ids of orders, lines and resends are
// made on the device (UUID v7). Business records are never deleted (TEN-06):
// areas, tables, stations and modifier groups are archived; orders end
// closed, merged or cancelled; lines are voided, not removed.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        $userRef = function (Blueprint $table, string $column): void {
            $table->uuid($column)->nullable();
            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['tenant_id', $column]);
        };

        // REST-13: a company's service charge and the core items that carry the charge and the tip on a sale.
        Schema::create('rest_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->decimal('service_charge_percent', 7, 4)->default(0);
            $table->foreignUuid('service_charge_item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->foreignUuid('tip_item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->jsonb('tip_presets')->default('[]');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id']);
            $table->index('service_charge_item_id');
            $table->index('tip_item_id');
        });
        DB::statement('alter table rest_settings add constraint rest_settings_check check (service_charge_percent between 0 and 30)');
        Rls::enable('rest_settings');

        // REST-02: areas of a location.
        Schema::create('rest_areas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->integer('sort')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id']);
            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement('create unique index rest_areas_name on rest_areas (tenant_id, location_id, lower(name)) where archived_at is null');
        Rls::enable('rest_areas');

        // REST-02: tables with seats, a place on the plan (grid cells) and a manual status.
        Schema::create('rest_tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('area_id')->constrained('rest_areas')->restrictOnDelete();
            $table->string('name', 60);
            $table->smallInteger('seats')->default(2);
            // The plan's grid cells (the API calls them x, y, width and height; `x` and `y` as column names would shadow a table alias in the tenant export).
            $table->smallInteger('plan_x')->default(0);
            $table->smallInteger('plan_y')->default(0);
            $table->smallInteger('plan_width')->default(8);
            $table->smallInteger('plan_height')->default(8);
            $table->string('shape', 10)->default('square');
            $table->string('manual_status', 10)->default('free');
            $table->integer('sort')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id']);
            $table->index('area_id');
            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement("alter table rest_tables add constraint rest_tables_check check (shape in ('square', 'round', 'rect') and manual_status in ('free', 'reserved', 'cleaning', 'blocked') and seats between 1 and 99 and plan_x between 0 and 99 and plan_y between 0 and 99 and plan_width between 1 and 99 and plan_height between 1 and 99)");
        DB::statement('create unique index rest_tables_name on rest_tables (tenant_id, location_id, lower(name)) where archived_at is null');
        Rls::enable('rest_tables');

        // REST-08: stations and the item categories routed to them.
        Schema::create('rest_stations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('kind', 10)->default('kitchen');
            $table->string('printer', 120)->nullable();
            $table->boolean('is_default')->default(false);
            $table->integer('sort')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id']);
            $table->index('company_id');
            $table->index('branch_id');
        });
        DB::statement("alter table rest_stations add constraint rest_stations_check check (kind in ('kitchen', 'bar', 'other'))");
        DB::statement('create unique index rest_stations_name on rest_stations (tenant_id, location_id, lower(name)) where archived_at is null');
        DB::statement('create unique index rest_stations_default on rest_stations (tenant_id, location_id) where is_default and archived_at is null');
        Rls::enable('rest_stations');

        Schema::create('rest_station_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('station_id')->constrained('rest_stations')->restrictOnDelete();
            $table->foreignUuid('item_category_id')->constrained('item_categories')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'location_id', 'item_category_id']);
            $table->index('station_id');
            $table->index('item_category_id');
            $table->index('location_id');
        });
        Rls::enable('rest_station_routes');

        // REST-07: modifier groups, their modifiers and what they attach to.
        Schema::create('rest_modifier_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->smallInteger('min_select')->default(0);
            $table->smallInteger('max_select')->default(1);
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
            $table->index('currency');
        });
        DB::statement('alter table rest_modifier_groups add constraint rest_modifier_groups_check check (min_select >= 0 and max_select >= 1 and min_select <= max_select)');
        Rls::enable('rest_modifier_groups');

        Schema::create('rest_modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('group_id')->constrained('rest_modifier_groups')->restrictOnDelete();
            $table->string('name', 120);
            $table->bigInteger('price_minor')->default(0);
            $table->integer('sort')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('group_id');
        });
        DB::statement('alter table rest_modifiers add constraint rest_modifiers_check check (price_minor >= 0)');
        Rls::enable('rest_modifiers');

        Schema::create('rest_modifier_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('group_id')->constrained('rest_modifier_groups')->restrictOnDelete();
            $table->foreignUuid('item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->foreignUuid('item_category_id')->nullable()->constrained('item_categories')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('group_id');
            $table->index('item_id');
            $table->index('item_category_id');
        });
        DB::statement('alter table rest_modifier_links add constraint rest_modifier_links_check check ((item_id is null) <> (item_category_id is null))');
        Rls::enable('rest_modifier_links');

        // REST-03 to REST-06: an order of a table or a tab. The device makes its id.
        Schema::create('rest_orders', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 5);
            $table->foreignUuid('table_id')->nullable()->constrained('rest_tables')->restrictOnDelete();
            $table->string('tab_name', 120)->nullable();
            $table->foreignUuid('customer_id')->nullable()->constrained('parties')->restrictOnDelete();
            $userRef($table, 'waiter_id');
            $userRef($table, 'updated_by');
            $table->smallInteger('covers')->default(1);
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->string('status', 10)->default('open');
            $table->text('note')->nullable();
            $table->uuid('merged_into_id')->nullable();
            $table->jsonb('flags')->default('[]');
            $table->bigInteger('tip_minor')->default(0);
            $table->integer('rev')->default(1);
            $table->timestampTz('opened_at');
            $table->timestampTz('header_at');
            $table->timestampTz('bill_requested_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id', 'status']);
            $table->index('table_id');
            $table->index('customer_id');
            $table->index('merged_into_id');
            $table->index('currency');
            $table->index('company_id');
            $table->index('branch_id');
            $table->index('device_id');
        });
        DB::statement("alter table rest_orders add constraint rest_orders_check check (kind in ('table', 'tab') and status in ('open', 'closed', 'merged', 'cancelled') and covers >= 0 and tip_minor >= 0 and ((kind = 'table') = (table_id is not null) or kind = 'tab'))");
        Schema::table('rest_orders', function (Blueprint $table) {
            $table->foreign('merged_into_id')->references('id')->on('rest_orders')->restrictOnDelete();
        });
        Rls::enable('rest_orders');

        Schema::create('rest_order_lines', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('order_id')->constrained('rest_orders')->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->decimal('qty', 20, 6);
            $table->bigInteger('unit_price_minor');
            $table->foreignUuid('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_rate', 9, 4)->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->smallInteger('course')->default(1);
            $table->smallInteger('seat')->nullable();
            $table->string('note', 200)->nullable();
            $table->jsonb('modifiers')->default('[]');
            $table->integer('round')->nullable();
            $table->timestampTz('created_at_device');
            $table->timestampTz('sent_at')->nullable();
            $userRef($table, 'sent_by');
            $table->timestampTz('voided_at')->nullable();
            $userRef($table, 'voided_by');
            $userRef($table, 'void_approved_by');
            $table->string('void_reason', 255)->nullable();
            $table->boolean('void_after_send')->default(false);
            $table->jsonb('flags')->default('[]');
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
            $table->index('item_id');
            $table->index('tax_code_id');
            $table->index('location_id');
            $table->index(['tenant_id', 'location_id', 'voided_at']);
        });
        DB::statement('alter table rest_order_lines add constraint rest_order_lines_check check (qty > 0 and unit_price_minor >= 0 and course between 1 and 9)');
        Rls::enable('rest_order_lines');

        // REST-09, REST-10: tickets per station and round; a re-send and a void make their own.
        Schema::create('rest_tickets', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('order_id')->constrained('rest_orders')->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('station_id')->nullable()->constrained('rest_stations')->restrictOnDelete();
            $table->date('business_date');
            $table->integer('number');
            $table->string('kind', 6)->default('order');
            $table->string('status', 10)->default('new');
            $table->smallInteger('course')->default(1);
            $table->integer('round')->nullable();
            $table->boolean('offline')->default(false);
            $table->timestampTz('preparing_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('served_at')->nullable();
            $userRef($table, 'updated_by');
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id', 'status']);
            $table->index('order_id');
            $table->index('station_id');
            $table->unique(['tenant_id', 'location_id', 'business_date', 'number']);
        });
        DB::statement("alter table rest_tickets add constraint rest_tickets_check check (kind in ('order', 'resend', 'void') and status in ('new', 'preparing', 'ready', 'served'))");
        Rls::enable('rest_tickets');

        Schema::create('rest_ticket_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('ticket_id')->constrained('rest_tickets')->restrictOnDelete();
            $table->foreignUuid('order_line_id')->constrained('rest_order_lines')->restrictOnDelete();
            $table->string('name', 255);
            $table->decimal('qty', 20, 6);
            $table->jsonb('modifiers')->default('[]');
            $table->string('note', 200)->nullable();
            $table->boolean('void')->default(false);
            $table->timestampsTz();

            $table->index('ticket_id');
            $table->index('order_line_id');
        });
        Rls::enable('rest_ticket_lines');

        // Idempotent re-sends: the device's id of a resend, kept so a repeat makes no second ticket.
        Schema::create('rest_resends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('order_id')->constrained('rest_orders')->restrictOnDelete();
            $table->foreignUuid('ticket_id')->nullable()->constrained('rest_tickets')->restrictOnDelete();
            $table->timestampTz('resent_at');
            $table->timestampsTz();

            $table->index('order_id');
            $table->index('ticket_id');
        });
        Rls::enable('rest_resends');

        // REST-16: a sale that paid (part of) an order. One per sale.
        Schema::create('rest_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('order_id')->constrained('rest_orders')->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->uuid('sale_id');
            $table->string('mode', 10);
            $table->smallInteger('part')->default(1);
            $table->smallInteger('parts')->default(1);
            $table->boolean('final')->default(false);
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->bigInteger('total_minor');
            $table->bigInteger('tip_minor')->default(0);
            $table->bigInteger('service_charge_minor')->default(0);
            $table->jsonb('line_ids')->default('[]');
            $table->timestampTz('settled_at');
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'sale_id']);
            $table->index('order_id');
            $table->index('location_id');
            $table->index('currency');
        });
        Rls::enable('rest_settlements');
    }

    public function down(): void
    {
        foreach (['rest_settlements', 'rest_resends', 'rest_ticket_lines', 'rest_tickets', 'rest_order_lines', 'rest_orders', 'rest_modifier_links', 'rest_modifiers', 'rest_modifier_groups', 'rest_station_routes', 'rest_stations', 'rest_tables', 'rest_areas', 'rest_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
