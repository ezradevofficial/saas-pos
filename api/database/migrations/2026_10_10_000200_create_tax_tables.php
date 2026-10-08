<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MD-03, CP-02: each company's tax codes (copied from its country pack, or
// its own) with effective-dated rates; tax categories (shared across the
// group when company_id is null, TEN-08 wiring in a later task) with a
// default tax code per company; price lists, tax-inclusive or exclusive,
// one default per company and currency.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('kind', 20);
            // The pack tax code this was copied from (CP-01); null for the tenant's own codes.
            $table->string('pack_code', 30)->nullable();
            $table->string('fiscal_code', 50)->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            // Target of the composite key on tax_category_codes.
            $table->unique(['id', 'company_id']);
        });

        DB::statement("alter table tax_codes add constraint tax_codes_kind_check check (kind in ('vat', 'withholding', 'excise', 'exempt', 'zero_rated'))");
        // Codes are stored upper case; unique among the company's active codes.
        DB::statement('create unique index tax_codes_company_code_active_unique on tax_codes (company_id, code) where archived_at is null');
        // A pack code is copied once per company, archived or not.
        DB::statement('create unique index tax_codes_company_pack_code_unique on tax_codes (company_id, pack_code) where pack_code is not null');

        Rls::enable('tax_codes');

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('tax_code_id')->constrained()->restrictOnDelete();
            // Percent (12.5000 = 12.5 %). NULL: rate needed (never invented).
            $table->decimal('rate', 9, 4)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('needs_confirmation')->default(false);
            // CP-02, CP-03: 'pack' rows were copied from the country pack and
            // follow its new versions; 'tenant' rows were entered by the tenant.
            $table->string('source', 10)->default('tenant');
            $table->timestampsTz();

            $table->unique(['tax_code_id', 'effective_from']);
        });

        DB::statement('alter table tax_rates add constraint tax_rates_rate_check check (rate is null or (rate >= 0 and rate <= 100))');
        DB::statement('alter table tax_rates add constraint tax_rates_unconfirmed_check check (rate is not null or needs_confirmation)');
        DB::statement("alter table tax_rates add constraint tax_rates_source_check check (source in ('pack', 'tenant'))");
        DB::statement('alter table tax_rates add constraint tax_rates_dates_check check (effective_to is null or effective_to >= effective_from)');

        Rls::enable('tax_rates');

        Schema::create('tax_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            // Null: shared across the group's companies.
            $table->foreignUuid('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        Rls::enable('tax_categories');

        Schema::create('tax_category_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('tax_category_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->uuid('tax_code_id');
            $table->timestampsTz();

            // The default tax code belongs to the company it is the default for.
            $table->foreign(['tax_code_id', 'company_id'])->references(['id', 'company_id'])->on('tax_codes')->restrictOnDelete();
            $table->unique(['tax_category_id', 'company_id']);
        });

        Rls::enable('tax_category_codes');

        Schema::create('price_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->char('currency', 3);
            $table->boolean('tax_inclusive')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        DB::statement('create unique index price_lists_one_default_unique on price_lists (company_id, currency) where is_default and archived_at is null');

        Rls::enable('price_lists');
    }

    public function down(): void
    {
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('tax_category_codes');
        Schema::dropIfExists('tax_categories');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_codes');
    }
};
