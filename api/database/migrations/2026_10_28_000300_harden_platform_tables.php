<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR 013 hardening (SUB-01, AUD-01):
// - Only the platform role (app_platform, connection pgsql_platform) reaches
//   the staff tables and writes the catalogue. The tenant runtime role has no
//   access to staff, tokens or their audit log, and may only read the
//   catalogue (public catalogue and quote, billing snapshots).
// - A price or annual discount is never dated before today (UTC).
// - A coupon's value and scope never change once created.
// - What a module or bundle is made of never changes while it has a live price.
// Triggers bind every role, the owner included.
return new class extends Migration
{
    private const PLATFORM_TABLES = ['platform_users', 'platform_access_tokens', 'platform_audit_logs'];

    private const CATALOGUE_TABLES = ['catalogue_countries', 'catalogue_modules', 'catalogue_bundles', 'catalogue_prices', 'catalogue_annual_discounts', 'catalogue_coupons'];

    public function up(): void
    {
        $runtime = $this->quoted(config('database.connections.pgsql.username') ?: 'app');
        $platformRole = config('database.connections.pgsql_platform.username') ?: 'app_platform';

        if (DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$platformRole]) === null) {
            throw new RuntimeException("The database role [{$platformRole}] is missing. Create it first (database/scripts/create-roles.sql, ADR 013).");
        }

        $platform = $this->quoted($platformRole);
        $staff = implode(', ', self::PLATFORM_TABLES);
        $catalogue = implode(', ', self::CATALOGUE_TABLES);

        DB::statement("grant usage on schema public to {$platform}");
        DB::statement("revoke all on {$staff} from {$runtime}");
        DB::statement("revoke insert, update, delete, truncate, references, trigger on {$catalogue} from {$runtime}");
        DB::statement("grant select on {$catalogue} to {$runtime}");

        DB::statement("grant select, insert, update on platform_users to {$platform}");
        DB::statement("grant select, insert, update, delete on platform_access_tokens to {$platform}");
        DB::statement("grant select, insert on platform_audit_logs to {$platform}");
        DB::statement("grant select on catalogue_countries to {$platform}");
        DB::statement("grant select, insert, update on catalogue_modules, catalogue_bundles, catalogue_prices, catalogue_annual_discounts, catalogue_coupons to {$platform}");

        DB::unprepared(<<<'SQL'
            -- A price or annual discount starts today or later (UTC dates).
            create or replace function catalogue_not_backdated() returns trigger
            language plpgsql as $$
            begin
                if new.effective_from < (now() at time zone 'UTC')::date then
                    raise exception 'catalogue rows start today or later (UTC)' using errcode = 'check_violation';
                end if;
                return new;
            end;
            $$;

            create trigger catalogue_prices_not_backdated
                before insert on catalogue_prices
                for each row execute function catalogue_not_backdated();

            create trigger catalogue_annual_discounts_not_backdated
                before insert on catalogue_annual_discounts
                for each row execute function catalogue_not_backdated();

            -- A coupon's value and scope are fixed once created.
            create or replace function catalogue_coupon_value_fixed() returns trigger
            language plpgsql as $$
            begin
                if new.code is distinct from old.code
                    or new.kind is distinct from old.kind
                    or new.percent is distinct from old.percent
                    or new.amount is distinct from old.amount
                    or new.currency is distinct from old.currency
                    or new.applies_to_modules is distinct from old.applies_to_modules
                    or new.applies_to_bundles is distinct from old.applies_to_bundles then
                    raise exception 'a coupon''s value and scope never change' using errcode = 'insufficient_privilege';
                end if;
                return new;
            end;
            $$;

            create trigger catalogue_coupons_value_fixed
                before update on catalogue_coupons
                for each row execute function catalogue_coupon_value_fixed();

            -- What a module is made of is fixed while it has a live price.
            create or replace function catalogue_module_makeup_fixed() returns trigger
            language plpgsql as $$
            begin
                if new.key is distinct from old.key then
                    raise exception 'a catalogue key never changes' using errcode = 'insufficient_privilege';
                end if;
                if (new.kind is distinct from old.kind or new.unit is distinct from old.unit
                        or new.includes is distinct from old.includes or new.requires is distinct from old.requires)
                    and exists (select 1 from public.catalogue_prices p
                                where p.item_type = 'module' and p.item_key = old.key and p.archived_at is null) then
                    raise exception 'a priced module keeps its make-up: add a new module instead' using errcode = 'insufficient_privilege';
                end if;
                return new;
            end;
            $$;

            create trigger catalogue_modules_makeup_fixed
                before update on catalogue_modules
                for each row execute function catalogue_module_makeup_fixed();

            -- What a bundle is made of is fixed while it has a live price.
            create or replace function catalogue_bundle_makeup_fixed() returns trigger
            language plpgsql as $$
            begin
                if new.key is distinct from old.key then
                    raise exception 'a catalogue key never changes' using errcode = 'insufficient_privilege';
                end if;
                if (new.quoted is distinct from old.quoted or new.modules is distinct from old.modules
                        or new.addons is distinct from old.addons or new.included_outlets is distinct from old.included_outlets)
                    and exists (select 1 from public.catalogue_prices p
                                where p.item_type = 'bundle' and p.item_key = old.key and p.archived_at is null) then
                    raise exception 'a priced bundle keeps its make-up: add a new bundle instead' using errcode = 'insufficient_privilege';
                end if;
                return new;
            end;
            $$;

            create trigger catalogue_bundles_makeup_fixed
                before update on catalogue_bundles
                for each row execute function catalogue_bundle_makeup_fixed();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists catalogue_bundles_makeup_fixed on catalogue_bundles;
            drop trigger if exists catalogue_modules_makeup_fixed on catalogue_modules;
            drop trigger if exists catalogue_coupons_value_fixed on catalogue_coupons;
            drop trigger if exists catalogue_annual_discounts_not_backdated on catalogue_annual_discounts;
            drop trigger if exists catalogue_prices_not_backdated on catalogue_prices;
            drop function if exists catalogue_bundle_makeup_fixed();
            drop function if exists catalogue_module_makeup_fixed();
            drop function if exists catalogue_coupon_value_fixed();
            drop function if exists catalogue_not_backdated();
            SQL);
    }

    private function quoted(string $role): string
    {
        return '"'.str_replace('"', '""', $role).'"';
    }
};
