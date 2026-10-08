<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MD-03 follow-up: an item's price in a price list, per unit (the item's
// base unit or one of its other units), effective-dated so a change can be
// scheduled, with an optional quantity break (min_quantity). The amount is
// stored in minor units in the price list's currency (ADR 003): the
// composite key to price_lists (id, currency) makes the database refuse a
// price in another currency, and a list's currency change while it has
// prices. Archived, never deleted (TEN-06).
return new class extends Migration
{
    public function up(): void
    {
        // Target of the composite key below.
        Schema::table('price_lists', function (Blueprint $table) {
            $table->unique(['id', 'currency'], 'price_lists_id_currency_unique');
        });

        Schema::create('item_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('price_list_id');
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('effective_from');
            // Quantity break, in the price's unit: the price applies from this quantity.
            $table->decimal('min_quantity', 18, 6)->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['price_list_id', 'currency'])->references(['id', 'currency'])->on('price_lists')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->index(['price_list_id', 'item_id']);
            $table->index('item_id');
            // NFR-04: POS master data syncs down by updated_at cursors.
            $table->index(['tenant_id', 'updated_at', 'id']);
        });

        DB::statement('alter table item_prices add constraint item_prices_amount_check check (amount_minor >= 0)');
        DB::statement('alter table item_prices add constraint item_prices_min_quantity_check check (min_quantity > 0)');
        // At most one active price per list, item, unit, start date and quantity break.
        DB::statement('create unique index item_prices_active_unique on item_prices (price_list_id, item_id, uom_id, effective_from, min_quantity) where archived_at is null');
        Rls::enable('item_prices');
    }

    public function down(): void
    {
        Schema::dropIfExists('item_prices');
        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropUnique('price_lists_id_currency_unique');
        });
    }
};
