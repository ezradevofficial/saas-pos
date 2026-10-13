<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// SUB-01, ONB-03: the plan catalogue. Global tables (no tenant_id, no RLS,
// ADR 013): the same modules, bundles and prices are offered to every
// tenant, and platform staff maintain them. Prices and the annual discount
// are effective-dated rows that are never edited once written: a change is
// a new row with a later effective date. Concept note 8.4 is seeded below,
// so every environment has it.
return new class extends Migration
{
    private const EFFECTIVE_FROM = '2026-10-01';

    public function up(): void
    {
        Schema::create('catalogue_countries', function (Blueprint $table) {
            $table->char('country', 2)->primary();
            $table->char('currency', 3);
            $table->timestampsTz();
        });

        Schema::create('catalogue_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 40)->unique();
            // module (sold alone), extra (more units of another module), addon (only with a bundle that lists it)
            $table->string('kind', 10)->default('module');
            $table->string('unit', 20);
            $table->jsonb('name');
            // Units of another catalogue entry included per unit of this one: {"pos_device": 2}.
            $table->jsonb('includes')->default('{}');
            $table->string('requires', 40)->nullable();
            $table->integer('sort')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('catalogue_bundles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 40)->unique();
            $table->jsonb('name');
            $table->jsonb('modules')->default('[]');
            $table->jsonb('addons')->default('[]');
            $table->integer('included_outlets')->default(1);
            // Enterprise: no list price, contact sales. The calculator never prices it.
            $table->boolean('quoted')->default(false);
            $table->integer('sort')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('catalogue_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('item_type', 10);
            $table->string('item_key', 40);
            $table->char('country', 2);
            $table->char('currency', 3);
            // Minor units, per unit a month. For a quoted bundle, the "from" figure shown to sales only.
            $table->bigInteger('amount');
            $table->bigInteger('minimum_amount')->nullable();
            $table->bigInteger('extra_outlet_amount')->nullable();
            $table->date('effective_from');
            $table->uuid('created_by')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['item_type', 'item_key', 'country', 'effective_from']);
            $table->foreign('country')->references('country')->on('catalogue_countries')->restrictOnDelete();
        });

        DB::statement("alter table catalogue_prices add constraint catalogue_prices_item_type_check check (item_type in ('module', 'bundle'))");
        DB::statement('alter table catalogue_prices add constraint catalogue_prices_amounts_check check (amount >= 0 and coalesce(minimum_amount, 0) >= 0 and coalesce(extra_outlet_amount, 0) >= 0)');
        DB::statement('create unique index catalogue_prices_live_unique on catalogue_prices (item_type, item_key, country, effective_from) where archived_at is null');

        Schema::create('catalogue_annual_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // "2 months free": 10 months charged for 12.
            $table->smallInteger('months_charged');
            $table->smallInteger('months_in_cycle')->default(12);
            $table->date('effective_from');
            $table->uuid('created_by')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('alter table catalogue_annual_discounts add constraint catalogue_annual_discounts_months_check check (months_in_cycle between 1 and 12 and months_charged between 1 and months_in_cycle)');
        DB::statement('create unique index catalogue_annual_discounts_live_unique on catalogue_annual_discounts (effective_from) where archived_at is null');

        Schema::create('catalogue_coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40);
            $table->string('kind', 10);
            $table->smallInteger('percent')->nullable();
            $table->bigInteger('amount')->nullable();
            $table->char('currency', 3)->nullable();
            // Empty lists: every line. Otherwise only these modules or bundles.
            $table->jsonb('applies_to_modules')->default('[]');
            $table->jsonb('applies_to_bundles')->default('[]');
            $table->timestampTz('expires_at')->nullable();
            $table->integer('max_redemptions')->nullable();
            $table->integer('redemptions')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('alter table catalogue_coupons alter column code type citext');
        DB::statement('alter table catalogue_coupons add constraint catalogue_coupons_code_unique unique (code)');
        DB::statement(<<<'SQL'
            alter table catalogue_coupons add constraint catalogue_coupons_kind_check check (
                (kind = 'percent' and percent between 1 and 100 and amount is null and currency is null)
                or (kind = 'fixed' and percent is null and amount > 0 and currency is not null)
            )
            SQL);
        DB::statement('alter table catalogue_coupons add constraint catalogue_coupons_redemptions_check check (redemptions >= 0 and (max_redemptions is null or max_redemptions >= 1))');

        // SUB-01: a price or annual discount row is never edited. The only
        // change allowed is archiving a row that has not taken effect yet
        // (a mistake caught before it applies). Binds every role.
        DB::unprepared(<<<'SQL'
            create or replace function catalogue_versioned_row_guard() returns trigger
            language plpgsql as $$
            begin
                if tg_op = 'UPDATE'
                    and old.archived_at is null
                    and new.archived_at is not null
                    and old.effective_from > current_date
                    and (to_jsonb(new) - 'archived_at' - 'updated_at') = (to_jsonb(old) - 'archived_at' - 'updated_at') then
                    return new;
                end if;

                raise exception 'catalogue prices are versioned: add a new row instead of editing one' using errcode = 'insufficient_privilege';
            end;
            $$;

            create trigger catalogue_prices_versioned
                before update or delete on catalogue_prices
                for each row execute function catalogue_versioned_row_guard();

            create trigger catalogue_annual_discounts_versioned
                before update or delete on catalogue_annual_discounts
                for each row execute function catalogue_versioned_row_guard();
            SQL);

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("revoke delete, truncate on catalogue_prices, catalogue_annual_discounts, catalogue_modules, catalogue_bundles, catalogue_coupons from {$runtimeRole}");
        // The countries and their billing currency are fixed by the owner (KE in KES, CD in USD).
        DB::statement("revoke insert, update, delete, truncate on catalogue_countries from {$runtimeRole}");

        $this->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogue_coupons');
        Schema::dropIfExists('catalogue_annual_discounts');
        Schema::dropIfExists('catalogue_prices');
        Schema::dropIfExists('catalogue_bundles');
        Schema::dropIfExists('catalogue_modules');
        Schema::dropIfExists('catalogue_countries');
        DB::statement('drop function if exists catalogue_versioned_row_guard()');
    }

    /** Concept note 8.4, exactly, in minor units (KES 2,500.00 is 250000; USD 1.50 is 150). */
    private function seed(): void
    {
        $now = now();

        DB::table('catalogue_countries')->insert([
            ['country' => 'KE', 'currency' => 'KES', 'created_at' => $now, 'updated_at' => $now],
            ['country' => 'CD', 'currency' => 'USD', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // key => [kind, unit, en, fr, KES, USD, minimum KES, minimum USD, includes, requires], amounts in minor units
        $modules = [
            'pos' => ['module', 'outlet', 'Point of sale', 'Point de vente', 250000, 2000, null, null, ['pos_device' => 2], null],
            'pos_device' => ['extra', 'device', 'Extra POS device', 'Appareil de caisse supplémentaire', 50000, 400, null, null, [], 'pos'],
            'inventory' => ['module', 'outlet', 'Inventory', 'Stocks', 150000, 1200, null, null, [], null],
            'stores' => ['module', 'outlet', 'Stores', 'Magasins', 100000, 800, null, null, [], null],
            'loyalty' => ['module', 'outlet', 'Loyalty, promotions and credit', 'Fidélité, promotions et crédit', 100000, 800, null, null, [], null],
            'ecommerce' => ['module', 'online_store', 'E-commerce and delivery', 'E-commerce et livraison', 250000, 2000, null, null, [], null],
            'accounting_essentials' => ['module', 'company', 'Accounting Essentials', 'Comptabilité Essentiel', 350000, 2800, null, null, [], null],
            'accounting_pro' => ['module', 'company', 'Accounting Pro', 'Comptabilité Pro', 700000, 5500, null, null, [], null],
            'procurement' => ['module', 'company', 'Procurement', 'Achats', 300000, 2500, null, null, [], null],
            'crm' => ['module', 'company', 'CRM and sales', 'CRM et ventes', 200000, 1500, null, null, [], null],
            'fixed_assets' => ['module', 'company', 'Fixed assets', 'Immobilisations', 150000, 1200, null, null, [], null],
            'manufacturing' => ['module', 'company', 'Manufacturing', 'Production', 400000, 3000, null, null, [], null],
            'hr' => ['module', 'employee', 'HR, shifts and attendance', 'RH, horaires et présences', 10000, 100, 150000, 1200, [], null],
            'payroll' => ['module', 'employee', 'Payroll', 'Paie', 15000, 150, 200000, 1500, [], null],
            'brand_pack' => ['addon', 'tenant', 'Brand Pack', 'Pack marque', 150000, 1200, null, null, [], null],
        ];

        $sort = 0;
        foreach ($modules as $key => [$kind, $unit, $en, $fr, $kes, $usd, $minKes, $minUsd, $includes, $requires]) {
            DB::table('catalogue_modules')->insert([
                'id' => (string) Str::uuid7(),
                'key' => $key,
                'kind' => $kind,
                'unit' => $unit,
                'name' => json_encode(['en' => $en, 'fr' => $fr], JSON_UNESCAPED_UNICODE),
                'includes' => json_encode((object) $includes),
                'requires' => $requires,
                'sort' => $sort += 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->price('module', $key, 'KE', 'KES', $kes, $minKes);
            $this->price('module', $key, 'CD', 'USD', $usd, $minUsd);
        }

        $all = array_keys(array_filter($modules, fn ($m) => $m[0] === 'module'));

        // key => [en, fr, modules, addons, quoted, KES, USD, extra outlet KES, extra outlet USD], amounts in minor units
        $bundles = [
            'starter' => ['Starter', 'Démarrage', ['pos', 'inventory', 'loyalty'], ['brand_pack'], false, 390000, 3000, null, null],
            'business' => ['Business', 'Affaires', ['pos', 'inventory', 'stores', 'loyalty', 'accounting_essentials', 'procurement'], [], false, 990000, 7900, 400000, 3200],
            // "From 40,000 (quoted)": the figure is kept for sales, never used to price.
            'enterprise' => ['Enterprise', 'Entreprise', array_values(array_diff($all, ['hr', 'payroll'])), [], true, 4000000, 32000, null, null],
        ];

        $sort = 0;
        foreach ($bundles as $key => [$en, $fr, $included, $addons, $quoted, $kes, $usd, $extraKes, $extraUsd]) {
            DB::table('catalogue_bundles')->insert([
                'id' => (string) Str::uuid7(),
                'key' => $key,
                'name' => json_encode(['en' => $en, 'fr' => $fr], JSON_UNESCAPED_UNICODE),
                'modules' => json_encode($included),
                'addons' => json_encode($addons),
                'included_outlets' => 1,
                'quoted' => $quoted,
                'sort' => $sort += 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->price('bundle', $key, 'KE', 'KES', $kes, null, $extraKes);
            $this->price('bundle', $key, 'CD', 'USD', $usd, null, $extraUsd);
        }

        DB::table('catalogue_annual_discounts')->insert([
            'id' => (string) Str::uuid7(),
            'months_charged' => 10,
            'months_in_cycle' => 12,
            'effective_from' => self::EFFECTIVE_FROM,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Amounts in minor units (KES and USD both have 2 decimals). */
    private function price(string $type, string $key, string $country, string $currency, int $amount, ?int $minimum, ?int $extraOutlet = null): void
    {
        DB::table('catalogue_prices')->insert([
            'id' => (string) Str::uuid7(),
            'item_type' => $type,
            'item_key' => $key,
            'country' => $country,
            'currency' => $currency,
            'amount' => $amount,
            'minimum_amount' => $minimum,
            'extra_outlet_amount' => $extraOutlet,
            'effective_from' => self::EFFECTIVE_FROM,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
