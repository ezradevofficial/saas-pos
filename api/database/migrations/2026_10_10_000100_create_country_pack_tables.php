<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// CP-01, CP-02, CP-03: country packs are versioned data. Each published
// version of a pack (KE, CD) and its tax codes with effective-dated rates.
// Global reference tables: no tenant_id; written only by the schema owner
// (`country-packs:publish`), the runtime role may only read them (ADR 002,
// like `permissions` and `currencies`). Rates nobody has confirmed from an
// official source are NULL with needs_confirmation = true; never invented.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('country_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('code', 2);
            $table->integer('version');
            // sha256 of the pack file's canonical JSON: a re-publish of the same content is a no-op.
            $table->char('content_hash', 64);
            $table->timestampTz('published_at');
            $table->jsonb('summary');
            $table->timestampsTz();

            $table->unique(['code', 'version']);
        });

        DB::statement("alter table country_packs add constraint country_packs_code_check check (code ~ '^[A-Z]{2}$')");
        DB::statement('alter table country_packs add constraint country_packs_version_check check (version >= 1)');

        Schema::create('country_pack_tax_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('country_pack_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            // Labels live in lang/{locale}/country_packs.php keyed "{pack}.{code}".
            $table->string('kind', 20);
            // Percent (12.5000 = 12.5 %). NULL: no confirmed figure.
            $table->decimal('rate', 9, 4)->nullable();
            $table->boolean('needs_confirmation');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // KRA eTIMS tax band / DGI tax group: null until known (phase 4 integrations).
            $table->string('fiscal_code', 50)->nullable();
            $table->timestampsTz();

            $table->unique(['country_pack_id', 'code', 'effective_from']);
        });

        DB::statement("alter table country_pack_tax_codes add constraint country_pack_tax_codes_kind_check check (kind in ('vat', 'withholding', 'excise', 'exempt', 'zero_rated'))");
        DB::statement('alter table country_pack_tax_codes add constraint country_pack_tax_codes_rate_check check (rate is null or (rate >= 0 and rate <= 100))');
        DB::statement("alter table country_pack_tax_codes add constraint country_pack_tax_codes_exempt_check check (kind <> 'exempt' or rate is null)");
        DB::statement("alter table country_pack_tax_codes add constraint country_pack_tax_codes_zero_check check (kind <> 'zero_rated' or rate = 0)");
        DB::statement("alter table country_pack_tax_codes add constraint country_pack_tax_codes_unconfirmed_check check (rate is not null or kind = 'exempt' or needs_confirmation)");
        DB::statement('alter table country_pack_tax_codes add constraint country_pack_tax_codes_dates_check check (effective_to is null or effective_to >= effective_from)');

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("revoke insert, update, delete, truncate on country_packs, country_pack_tax_codes from {$runtimeRole}");
    }

    public function down(): void
    {
        Schema::dropIfExists('country_pack_tax_codes');
        Schema::dropIfExists('country_packs');
    }
};
