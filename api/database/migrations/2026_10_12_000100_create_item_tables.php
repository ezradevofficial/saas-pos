<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MD-02: the items catalogue (core part). Units of measure are the
// tenant's; item categories (a tree) and items are shared across the group
// (company_id null) or one company's, following the `items` sharing mode
// (TEN-08). Codes are case-insensitive (citext) and barcodes normalised;
// both are unique among active items within the sharing scope (review
// focus 4). Kit components come with Inventory.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uoms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('code', 10);
            $table->string('name_en', 100);
            $table->string('name_fr', 100);
            $table->string('kind', 10);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('alter table uoms alter column code type citext');
        DB::statement("alter table uoms add constraint uoms_kind_check check (kind in ('count', 'weight', 'volume', 'length', 'time'))");
        DB::statement('create unique index uoms_code_unique on uoms (tenant_id, code) where archived_at is null');
        Rls::enable('uoms');

        Schema::create('item_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            // Null: shared across the group's companies.
            $table->foreignUuid('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('name_en', 100)->nullable();
            $table->string('name_fr', 100)->nullable();
            // A design token name (LAY-05 uses it on POS tiles), never a colour value.
            $table->string('colour', 40)->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('parent_id');
            $table->index('company_id');
            $table->index(['tenant_id', 'updated_at', 'id']);
        });

        Schema::table('item_categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('item_categories')->restrictOnDelete();
        });
        DB::statement('alter table item_categories add constraint item_categories_name_check check (name_en is not null or name_fr is not null)');
        DB::statement('alter table item_categories add constraint item_categories_parent_check check (parent_id is null or parent_id <> id)');
        Rls::enable('item_categories');

        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            // Null: shared across the group's companies.
            $table->foreignUuid('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name_en')->nullable();
            $table->string('name_fr')->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained('item_categories')->restrictOnDelete();
            $table->string('type', 10);
            $table->foreignUuid('base_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignUuid('tax_category_id')->nullable()->constrained()->restrictOnDelete();
            // CF-06: custom field values (definitions come later).
            $table->jsonb('custom')->default('{}');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('category_id');
            // NFR-04: POS master data syncs down by updated_at cursors.
            $table->index(['tenant_id', 'updated_at', 'id']);
        });

        DB::statement('alter table items alter column code type citext');
        DB::statement("alter table items add constraint items_type_check check (type in ('stock', 'service', 'non_stock', 'kit'))");
        DB::statement('alter table items add constraint items_name_check check (name_en is not null or name_fr is not null)');
        DB::statement("alter table items add constraint items_custom_check check (jsonb_typeof(custom) = 'object')");
        // Review focus 4: one active item per code in the sharing scope.
        DB::statement('create unique index items_code_shared_unique on items (tenant_id, code) where company_id is null and archived_at is null');
        DB::statement('create unique index items_code_company_unique on items (company_id, code) where company_id is not null and archived_at is null');
        // `?search=`: code prefix, names by trigram; CF-06 filters.
        DB::statement('create index items_code_prefix on items (lower(code::text) text_pattern_ops)');
        DB::statement('create index items_name_en_trgm on items using gin (name_en gin_trgm_ops)');
        DB::statement('create index items_name_fr_trgm on items using gin (name_fr gin_trgm_ops)');
        DB::statement('create index items_custom_gin on items using gin (custom jsonb_path_ops)');
        Rls::enable('items');

        Schema::create('item_uoms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            // How many base units one of this unit holds. The base unit
            // itself (factor 1) is implicit and never stored here.
            $table->decimal('factor', 18, 6);
            $table->boolean('is_sales_default')->default(false);
            $table->boolean('is_purchase_default')->default(false);
            $table->timestampsTz();

            $table->unique(['item_id', 'uom_id']);
        });

        DB::statement('alter table item_uoms add constraint item_uoms_factor_check check (factor > 0)');
        DB::statement('create unique index item_uoms_sales_default on item_uoms (item_id) where is_sales_default');
        DB::statement('create unique index item_uoms_purchase_default on item_uoms (item_id) where is_purchase_default');
        Rls::enable('item_uoms');

        Schema::create('item_barcodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            // Null: the item's base unit.
            $table->foreignUuid('uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $table->string('barcode', 48);
            // Copies of the item's company and archive time, kept by the
            // triggers below, so the unique indexes see the sharing scope.
            $table->uuid('company_id')->nullable();
            $table->timestampTz('item_archived_at')->nullable();
            $table->timestampsTz();

            $table->index('item_id');
        });

        DB::statement("alter table item_barcodes add constraint item_barcodes_barcode_check check (barcode ~ '^[0-9A-Z]{1,48}$')");
        // Review focus 4: one active item per barcode in the sharing scope.
        DB::statement('create unique index item_barcodes_shared_unique on item_barcodes (tenant_id, barcode) where company_id is null and item_archived_at is null');
        DB::statement('create unique index item_barcodes_company_unique on item_barcodes (company_id, barcode) where company_id is not null and item_archived_at is null');
        DB::statement('create index item_barcodes_lookup on item_barcodes (tenant_id, barcode)');

        DB::unprepared(<<<'SQL'
            create or replace function item_barcodes_copy_scope() returns trigger language plpgsql as $$
            begin
                select i.company_id, i.archived_at into new.company_id, new.item_archived_at
                from items i where i.id = new.item_id;
                return new;
            end;
            $$;

            create trigger item_barcodes_copy_scope
                before insert or update of item_id on item_barcodes
                for each row execute function item_barcodes_copy_scope();

            create or replace function items_share_scope_with_barcodes() returns trigger language plpgsql as $$
            begin
                update item_barcodes set company_id = new.company_id, item_archived_at = new.archived_at
                where item_id = new.id;
                return null;
            end;
            $$;

            create trigger items_share_scope_with_barcodes
                after update of company_id, archived_at on items
                for each row
                when (old.company_id is distinct from new.company_id or old.archived_at is distinct from new.archived_at)
                execute function items_share_scope_with_barcodes();
            SQL);

        Rls::enable('item_barcodes');

        Schema::create('item_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            $table->string('disk', 20);
            $table->string('path')->unique();
            $table->smallInteger('position');
            $table->string('mime', 20);
            $table->integer('size');
            $table->integer('width');
            $table->integer('height');
            $table->timestampsTz();
        });

        // Deferred: a reorder swaps positions inside one transaction.
        DB::statement('alter table item_images add constraint item_images_position_unique unique (item_id, position) deferrable initially deferred');
        DB::statement('alter table item_images add constraint item_images_position_check check (position >= 1)');
        Rls::enable('item_images');
    }

    public function down(): void
    {
        Schema::dropIfExists('item_images');
        Schema::dropIfExists('item_barcodes');
        DB::unprepared('drop function if exists item_barcodes_copy_scope(); drop function if exists items_share_scope_with_barcodes();');
        Schema::dropIfExists('item_uoms');
        Schema::dropIfExists('items');
        Schema::dropIfExists('item_categories');
        Schema::dropIfExists('uoms');
    }
};
