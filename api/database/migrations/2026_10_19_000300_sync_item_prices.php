<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// NFR-04, MD-03 follow-up: item prices sync to tills as the incremental
// entity `item_prices` (ItemPriceSource). Change markers as in
// 2026_10_19_000100_create_sync_tables. A price never leaves its list's
// company and is never deleted, so it needs no tombstone trigger: an
// archived price, or one the device should no longer hold, is left out of
// the payload and reaches the device as a tombstone once re-stamped. These
// re-stamp a list's or an item's prices when they stop (or start again)
// being usable: the list archived or restored; the item archived,
// restored, moved to another company or given another base unit; a unit
// added to or removed from the item.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_prices', function (Blueprint $table) {
            $table->bigInteger('sync_xid')->default(0);
            $table->bigInteger('sync_seq')->default(0);
        });

        DB::statement('create trigger sync_stamp before insert or update on item_prices for each row execute function sync_stamp()');
        DB::statement('update item_prices set sync_seq = 0');
        DB::statement('create index item_prices_sync_cursor on item_prices (tenant_id, sync_xid, sync_seq)');

        DB::unprepared(<<<'SQL'
            create or replace function sync_touch_prices_of_list() returns trigger language plpgsql as $$
            begin
                if old.archived_at is distinct from new.archived_at then
                    update item_prices set sync_seq = 0 where price_list_id = new.id;
                end if;
                return null;
            end;
            $$;

            create or replace function sync_touch_prices_of_item() returns trigger language plpgsql as $$
            begin
                if old.archived_at is distinct from new.archived_at
                    or old.company_id is distinct from new.company_id
                    or old.base_uom_id is distinct from new.base_uom_id then
                    update item_prices set sync_seq = 0 where item_id = new.id;
                end if;
                return null;
            end;
            $$;

            create or replace function sync_touch_prices_of_unit() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    update item_prices set sync_seq = 0 where item_id = old.item_id and uom_id = old.uom_id;
                else
                    update item_prices set sync_seq = 0 where item_id = new.item_id and uom_id = new.uom_id;
                end if;
                return null;
            end;
            $$;

            create trigger sync_touch_prices after update of archived_at on price_lists
                for each row execute function sync_touch_prices_of_list();
            create trigger sync_touch_prices after update of archived_at, company_id, base_uom_id on items
                for each row execute function sync_touch_prices_of_item();
            create trigger sync_touch_prices after insert or delete on item_uoms
                for each row execute function sync_touch_prices_of_unit();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists sync_touch_prices on price_lists;
            drop trigger if exists sync_touch_prices on items;
            drop trigger if exists sync_touch_prices on item_uoms;
            drop function if exists sync_touch_prices_of_list();
            drop function if exists sync_touch_prices_of_item();
            drop function if exists sync_touch_prices_of_unit();
            drop trigger if exists sync_stamp on item_prices;
            SQL);

        Schema::table('item_prices', fn (Blueprint $table) => $table->dropColumn(['sync_xid', 'sync_seq']));
    }
};
