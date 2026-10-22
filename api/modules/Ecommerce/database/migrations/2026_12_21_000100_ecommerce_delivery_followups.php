<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// E-commerce delivery follow-ups (docs/modules/ecommerce.md, ECOM-40 to
// ECOM-44). Every new table is the tenant's, under forced row-level security
// (TEN-01, ECOM-38). Money is bigint minor units next to its currency, with
// the order's rate and base amounts (ADR 003, CUR-04); quantities
// numeric(20,6). Users are referenced by (tenant_id, id).
//
// - ecom_delivery_locations (ECOM-40): a rider's trail while a delivery is
//   active, cleared when it ends (KE DPA: minimum data); the latest point
//   stays on the delivery.
// - ecom_deliveries: the proof photo's type, size and time (ECOM-41).
// - ecom_returns, ecom_return_lines (ECOM-42, ECOM-43): partial returns and
//   exchanges of a delivered order. Never deleted (TEN-06).
// - ecom_orders.whatsapp_opt_in (ECOM-44): the customer asked for order
//   messages on WhatsApp.
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
        $currency = function (Blueprint $table, string $column): void {
            $table->char($column, 3);
            $table->foreign($column)->references('code')->on('currencies')->restrictOnDelete();
        };
        $check = fn (string $table, string $name, string $condition) => DB::statement("alter table {$table} add constraint {$table}_{$name}_check check ({$condition})");
        $in = fn (string $column, array $values) => $column.' in ('.implode(', ', array_map(fn (string $v) => "'{$v}'", $values)).')';

        // ECOM-40: the trail of an active delivery (coordinates nullable: anonymised by the privacy module, NFR-08).
        Schema::create('ecom_delivery_locations', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('delivery_id')->constrained('ecom_deliveries')->restrictOnDelete();
            $table->foreignUuid('rider_id')->constrained('ecom_riders')->restrictOnDelete();
            $userRef($table, 'user_id', nullable: false);
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->timestampTz('recorded_at');
            $table->timestampsTz();

            $table->index(['tenant_id', 'delivery_id', 'recorded_at']);
            $table->index(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'rider_id']);
            $table->index(['tenant_id', 'user_id']);
        });
        Rls::enable('ecom_delivery_locations');

        // ECOM-41: the proof photo on the media disk.
        Schema::table('ecom_deliveries', function (Blueprint $table) {
            $table->string('proof_photo_mime', 20)->nullable();
            $table->integer('proof_photo_size')->nullable();
            $table->timestampTz('proof_photo_at')->nullable();
        });

        // ECOM-44: order messages on WhatsApp, only when the customer asked.
        Schema::table('ecom_orders', function (Blueprint $table) {
            $table->boolean('whatsapp_opt_in')->default(false);
        });

        // ECOM-42, ECOM-43: a return of part of a delivered order, or an exchange.
        Schema::create('ecom_returns', function (Blueprint $table) use ($userRef, $currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('order_id')->constrained('ecom_orders')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->smallInteger('seq');
            $table->string('number', 90);
            $table->string('kind', 10);
            $table->string('reason', 500);
            $currency($table, 'currency');
            $table->bigInteger('returned_net_minor');
            $table->bigInteger('returned_tax_minor');
            $table->bigInteger('returned_total_minor');
            $table->bigInteger('exchange_net_minor')->default(0);
            $table->bigInteger('exchange_tax_minor')->default(0);
            $table->bigInteger('exchange_total_minor')->default(0);
            $table->bigInteger('refund_minor');
            $table->bigInteger('collect_minor');
            $currency($table, 'base_currency');
            $table->fxSnapshot('fx');
            $table->bigInteger('base_returned_total_minor');
            $table->bigInteger('base_exchange_total_minor')->default(0);
            $table->string('refund_status', 10);
            $table->string('refund_reference', 120)->nullable();
            // The payments the refund goes back to: [{payment_id, kind, amount_minor, intent_id?, status}].
            $table->jsonb('refund_detail')->default('[]');
            $table->timestampTz('refunded_at')->nullable();
            $userRef($table, 'refunded_by');
            $table->string('collect_status', 10);
            $table->foreignUuid('collect_payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->string('collect_reference', 120)->nullable();
            $table->timestampTz('collected_at')->nullable();
            $userRef($table, 'collected_by');
            // The exchange's sale (its posting and fiscal document), raised once nothing is left to collect.
            $table->uuid('exchange_sale_id')->nullable()->unique();
            $table->timestampTz('exchange_posted_at')->nullable();
            $userRef($table, 'created_by', nullable: false);
            $table->timestampTz('at');
            $table->jsonb('flags')->default('[]');
            $table->timestampsTz();

            $table->unique(['order_id', 'seq']);
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'location_id']);
            $table->index(['tenant_id', 'collect_payment_method_id']);
        });
        $check('ecom_returns', 'kind', $in('kind', ['return', 'exchange']));
        $check('ecom_returns', 'refund_status', $in('refund_status', ['none', 'due', 'refunded']));
        $check('ecom_returns', 'collect_status', $in('collect_status', ['none', 'due', 'collected']));
        $check('ecom_returns', 'amounts', 'seq >= 1 and returned_total_minor >= 0 and returned_tax_minor >= 0 and exchange_total_minor >= 0 and exchange_tax_minor >= 0'
            .' and refund_minor >= 0 and collect_minor >= 0 and (refund_minor = 0 or collect_minor = 0)'
            .' and returned_total_minor - exchange_total_minor = refund_minor - collect_minor');
        Rls::enable('ecom_returns');

        Schema::create('ecom_return_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('return_id')->constrained('ecom_returns')->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained('ecom_orders')->restrictOnDelete();
            $table->integer('line_no');
            // `in`: back from the customer (a line of the order); `out`: the exchange item given instead.
            $table->string('direction', 3);
            $table->foreignUuid('order_line_id')->nullable()->constrained('ecom_order_lines')->restrictOnDelete();
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

            $table->unique(['return_id', 'line_no']);
            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'order_line_id']);
            $table->index(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'item_id']);
            $table->index(['tenant_id', 'uom_id']);
            $table->index(['tenant_id', 'tax_code_id']);
        });
        $check('ecom_return_lines', 'direction', $in('direction', ['in', 'out']).' and ((direction = \'in\') = (order_line_id is not null))');
        $check('ecom_return_lines', 'values', 'qty > 0 and qty_base > 0 and unit_price_minor >= 0 and gross_minor >= 0 and discount_minor >= 0 and tax_minor >= 0 and total_minor >= 0');
        Rls::enable('ecom_return_lines');
    }

    public function down(): void
    {
        Schema::dropIfExists('ecom_return_lines');
        Schema::dropIfExists('ecom_returns');
        Schema::dropIfExists('ecom_delivery_locations');

        Schema::table('ecom_orders', fn (Blueprint $table) => $table->dropColumn('whatsapp_opt_in'));
        Schema::table('ecom_deliveries', fn (Blueprint $table) => $table->dropColumn(['proof_photo_mime', 'proof_photo_size', 'proof_photo_at']));
    }
};
