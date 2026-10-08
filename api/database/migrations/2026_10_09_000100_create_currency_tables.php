<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// CUR-01: the global ISO 4217 catalogue (written by `currencies:sync` as the
// owner; the runtime role may only read it, like `permissions`) and the
// currencies each tenant uses. CUR-02: each company's reporting currencies,
// and the time its base currency was locked by the first posting.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->smallInteger('numeric_code')->nullable();
            // Names are not stored: they come from ICU in the reader's locale (CUR-01).
            $table->smallInteger('default_decimals');
            $table->boolean('active_in_iso');
            $table->timestampsTz();
        });

        DB::statement("alter table currencies add constraint currencies_code_check check (code ~ '^[A-Z]{3}$')");
        DB::statement('alter table currencies add constraint currencies_default_decimals_check check (default_decimals between 0 and 4)');

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("revoke insert, update, delete, truncate on currencies from {$runtimeRole}");

        Schema::create('tenant_currencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->char('code', 3);
            $table->smallInteger('decimals');
            $table->bigInteger('cash_rounding_minor')->default(1);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->foreign('code')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
        });

        DB::statement('alter table tenant_currencies add constraint tenant_currencies_decimals_check check (decimals between 0 and 4)');
        DB::statement('alter table tenant_currencies add constraint tenant_currencies_cash_rounding_minor_check check (cash_rounding_minor >= 1)');

        Rls::enable('tenant_currencies');

        Schema::create('company_reporting_currencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->char('code', 3);
            $table->smallInteger('position');
            $table->timestampsTz();

            $table->foreign('code')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'position']);
        });

        DB::statement('alter table company_reporting_currencies add constraint company_reporting_currencies_position_check check (position between 1 and 3)');

        Rls::enable('company_reporting_currencies');

        Schema::table('companies', function (Blueprint $table) {
            $table->timestampTz('base_currency_locked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('base_currency_locked_at');
        });

        Schema::dropIfExists('company_reporting_currencies');
        Schema::dropIfExists('tenant_currencies');
        Schema::dropIfExists('currencies');
    }
};
