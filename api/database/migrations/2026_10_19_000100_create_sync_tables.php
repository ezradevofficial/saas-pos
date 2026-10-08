<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// NFR-04, ADR 004: POS master data syncs down in increments.
//
// Change markers. Each synced table gets `sync_xid` (the writing
// transaction's id) and `sync_seq` (a global sequence), stamped by the
// database on every insert and update (`sync_stamp`), never by PHP. Devices
// page through changes ordered by (sync_xid, sync_seq) and the server only
// hands out rows whose transaction id is below the oldest transaction still
// running, so a slow transaction that commits late can never fall behind a
// cursor (a plain updated_at cursor loses such rows). updated_at stays as it
// was and is still returned to devices.
//
// Tombstones. Archived rows arrive as tombstones from their own table. A row
// that leaves a device's scope (an item or category moved to another
// company, a party that is no longer a customer) or is deleted writes a
// `sync_tombstones` row naming the company it left (null: every company).
//
// Items carry their units, barcodes and images: a change to any of them,
// or to the tax data that decides whether the item may be sold (tax rates,
// a category's default code, a code's archive), re-stamps the item.
return new class extends Migration
{
    /** Tables devices pull incrementally, with their entity names for tombstones. */
    private const SYNCED = ['items' => 'items', 'item_categories' => 'item_categories', 'uoms' => 'uoms', 'parties' => 'customers'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create sequence sync_seq as bigint;

            -- UUID v7 (time-ordered) for rows written by triggers; PHP writes its own.
            create or replace function app_uuid_v7() returns uuid language sql volatile as $$
                select encode(
                    set_bit(set_bit(
                        overlay(uuid_send(gen_random_uuid())
                            placing substring(int8send((extract(epoch from clock_timestamp()) * 1000)::bigint) from 3)
                            from 1 for 6),
                        52, 1), 53, 1),
                    'hex')::uuid
            $$;

            create or replace function sync_stamp() returns trigger language plpgsql as $$
            begin
                new.sync_xid := pg_current_xact_id()::text::bigint;
                new.sync_seq := nextval('sync_seq');
                return new;
            end;
            $$;
            SQL);

        Schema::create('sync_tombstones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('entity', 40);
            $table->uuid('record_id');
            // The company whose devices must drop the record; null: every company's.
            $table->uuid('company_id')->nullable();
            $table->bigInteger('sync_xid')->default(0);
            $table->bigInteger('sync_seq')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'entity', 'sync_xid', 'sync_seq']);
        });

        DB::statement('alter table sync_tombstones alter column id set default app_uuid_v7()');
        DB::statement('create trigger sync_stamp before insert or update on sync_tombstones for each row execute function sync_stamp()');
        Rls::enable('sync_tombstones');

        foreach (array_keys(self::SYNCED) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->bigInteger('sync_xid')->default(0);
                $t->bigInteger('sync_seq')->default(0);
            });

            DB::statement("create trigger sync_stamp before insert or update on {$table} for each row execute function sync_stamp()");
            // Existing rows get a marker (the trigger stamps them).
            DB::statement("update {$table} set sync_seq = 0");
            DB::statement("create index {$table}_sync_cursor on {$table} (tenant_id, sync_xid, sync_seq)");
        }

        DB::unprepared(<<<'SQL'
            -- A row that leaves a company (or the group) or is deleted.
            create or replace function sync_tombstone_on_leave() returns trigger language plpgsql as $$
            declare
                old_company uuid := (to_jsonb(old) ->> 'company_id')::uuid;
            begin
                if tg_op = 'DELETE' or old_company is distinct from (to_jsonb(new) ->> 'company_id')::uuid then
                    insert into sync_tombstones (tenant_id, entity, record_id, company_id)
                    values (old.tenant_id, tg_argv[0], old.id, old_company);
                end if;
                return null;
            end;
            $$;

            -- A customer that is no longer one, moves company, or is deleted.
            create or replace function sync_tombstone_customer() returns trigger language plpgsql as $$
            begin
                if 'customer' = any(old.roles) and (
                    tg_op = 'DELETE'
                    or not ('customer' = any(new.roles))
                    or old.company_id is distinct from new.company_id
                ) then
                    insert into sync_tombstones (tenant_id, entity, record_id, company_id)
                    values (old.tenant_id, 'customers', old.id, old.company_id);
                end if;
                return null;
            end;
            $$;

            create trigger sync_tombstone after update of company_id or delete on items
                for each row execute function sync_tombstone_on_leave('items');
            create trigger sync_tombstone after update of company_id or delete on item_categories
                for each row execute function sync_tombstone_on_leave('item_categories');
            create trigger sync_tombstone after delete on uoms
                for each row execute function sync_tombstone_on_leave('uoms');
            create trigger sync_tombstone after update of company_id, roles or delete on parties
                for each row execute function sync_tombstone_customer();

            -- Units, barcodes and images travel inside their item.
            create or replace function sync_touch_item() returns trigger language plpgsql as $$
            begin
                update items set sync_seq = 0 where id in (old.item_id, new.item_id);
                return null;
            end;
            $$;

            create or replace function sync_touch_item_on_delete() returns trigger language plpgsql as $$
            begin
                update items set sync_seq = 0 where id = old.item_id;
                return null;
            end;
            $$;

            create or replace function sync_touch_item_on_insert() returns trigger language plpgsql as $$
            begin
                update items set sync_seq = 0 where id = new.item_id;
                return null;
            end;
            $$;

            create trigger sync_touch_item_insert after insert on item_uoms for each row execute function sync_touch_item_on_insert();
            create trigger sync_touch_item_update after update on item_uoms for each row execute function sync_touch_item();
            create trigger sync_touch_item_delete after delete on item_uoms for each row execute function sync_touch_item_on_delete();
            create trigger sync_touch_item_insert after insert on item_barcodes for each row execute function sync_touch_item_on_insert();
            -- Not on the copies of the item's company and archive time (the item changed already).
            create trigger sync_touch_item_update after update of item_id, uom_id, barcode on item_barcodes for each row execute function sync_touch_item();
            create trigger sync_touch_item_delete after delete on item_barcodes for each row execute function sync_touch_item_on_delete();
            create trigger sync_touch_item_insert after insert on item_images for each row execute function sync_touch_item_on_insert();
            create trigger sync_touch_item_update after update on item_images for each row execute function sync_touch_item();
            create trigger sync_touch_item_delete after delete on item_images for each row execute function sync_touch_item_on_delete();

            -- Whether an item may be sold follows its tax data (CP-02).
            create or replace function sync_touch_items_of_tax_rate() returns trigger language plpgsql as $$
            begin
                update items set sync_seq = 0
                where tax_category_id in (
                    select tax_category_id from tax_category_codes
                    where tax_code_id = case when tg_op = 'DELETE' then old.tax_code_id else new.tax_code_id end
                );
                return null;
            end;
            $$;

            create or replace function sync_touch_items_of_tax_code() returns trigger language plpgsql as $$
            begin
                update items set sync_seq = 0
                where tax_category_id in (select tax_category_id from tax_category_codes where tax_code_id = new.id);
                return null;
            end;
            $$;

            create or replace function sync_touch_items_of_tax_category() returns trigger language plpgsql as $$
            begin
                if tg_op <> 'INSERT' then
                    update items set sync_seq = 0 where tax_category_id = old.tax_category_id;
                end if;
                if tg_op <> 'DELETE' then
                    update items set sync_seq = 0 where tax_category_id = new.tax_category_id;
                end if;
                return null;
            end;
            $$;

            create trigger sync_touch_items after insert or update or delete on tax_rates
                for each row execute function sync_touch_items_of_tax_rate();
            create trigger sync_touch_items after update of archived_at on tax_codes
                for each row when (old.archived_at is distinct from new.archived_at)
                execute function sync_touch_items_of_tax_code();
            create trigger sync_touch_items after insert or update or delete on tax_category_codes
                for each row execute function sync_touch_items_of_tax_category();
            SQL);

        // NFR-04, TEN-05: when each device last pulled, pushed and bootstrapped
        // (back office), and its secret for PIN material and offline override
        // signatures (AUTH-06, AUTH-08), encrypted by the application.
        Schema::table('devices', function (Blueprint $table) {
            $table->timestampTz('last_pull_at')->nullable();
            $table->timestampTz('last_push_at')->nullable();
            $table->timestampTz('last_bootstrap_at')->nullable();
            $table->text('secret')->nullable();
            $table->timestampTz('secret_issued_at')->nullable();
        });

        // AUTH-06: a user's POS PIN and optional staff card. Argon2id hashes
        // for server checks; a PBKDF2-SHA256 key (encrypted by the
        // application) from which each device's verifier is derived. Never
        // the PIN or the card code.
        Schema::create('user_pins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('user_id')->unique()->constrained()->restrictOnDelete();
            $table->text('pin_hash')->nullable();
            $table->string('pin_salt', 64)->nullable();
            $table->integer('pin_iterations')->nullable();
            $table->text('pin_key')->nullable();
            $table->text('card_hash')->nullable();
            $table->string('card_salt', 64)->nullable();
            $table->integer('card_iterations')->nullable();
            $table->text('card_key')->nullable();
            // Raised on every change: devices see a new verifier, lockouts clear.
            $table->integer('version')->default(1);
            $table->timestampTz('pin_set_at')->nullable();
            $table->foreignUuid('set_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        Rls::enable('user_pins');

        // AUTH-06: wrong PINs per user and device; 5 lock the user's PIN on
        // that device until the PIN is changed.
        Schema::create('device_pin_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->integer('failed_attempts')->default(0);
            $table->timestampTz('last_failed_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['device_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement('alter table device_pin_states add constraint device_pin_states_failed_check check (failed_attempts >= 0)');
        Rls::enable('device_pin_states');

        // AUTH-08: each manager override used once (online token id or the
        // device's offline override id), with what it authorised.
        Schema::create('override_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('override_id');
            $table->string('mode', 10);
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('manager_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('cashier_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('permission', 150);
            $table->string('reference', 100)->nullable();
            $table->timestampTz('authorised_at');
            $table->timestampTz('redeemed_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'override_id']);
        });

        DB::statement("alter table override_redemptions add constraint override_redemptions_mode_check check (mode in ('online', 'offline'))");
        Rls::enable('override_redemptions');
    }

    public function down(): void
    {
        Schema::dropIfExists('override_redemptions');
        Schema::dropIfExists('device_pin_states');
        Schema::dropIfExists('user_pins');

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['last_pull_at', 'last_push_at', 'last_bootstrap_at', 'secret', 'secret_issued_at']);
        });

        DB::unprepared(<<<'SQL'
            drop trigger if exists sync_touch_items on tax_category_codes;
            drop trigger if exists sync_touch_items on tax_codes;
            drop trigger if exists sync_touch_items on tax_rates;
            drop function if exists sync_touch_items_of_tax_category();
            drop function if exists sync_touch_items_of_tax_code();
            drop function if exists sync_touch_items_of_tax_rate();
            SQL);

        foreach (['item_uoms', 'item_barcodes', 'item_images'] as $table) {
            foreach (['insert', 'update', 'delete'] as $op) {
                DB::statement("drop trigger if exists sync_touch_item_{$op} on {$table}");
            }
        }

        DB::unprepared(<<<'SQL'
            drop function if exists sync_touch_item();
            drop function if exists sync_touch_item_on_delete();
            drop function if exists sync_touch_item_on_insert();
            SQL);

        foreach (array_keys(self::SYNCED) as $table) {
            DB::statement("drop trigger if exists sync_tombstone on {$table}");
            DB::statement("drop trigger if exists sync_stamp on {$table}");
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['sync_xid', 'sync_seq']));
        }

        DB::unprepared(<<<'SQL'
            drop function if exists sync_tombstone_customer();
            drop function if exists sync_tombstone_on_leave();
            SQL);

        Schema::dropIfExists('sync_tombstones');

        DB::unprepared(<<<'SQL'
            drop function if exists sync_stamp();
            drop function if exists app_uuid_v7();
            drop sequence if exists sync_seq;
            SQL);
    }
};
