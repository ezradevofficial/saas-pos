<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// TEN-03: companies (legal entities) of a tenant.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('name');
            $table->string('legal_name');
            $table->string('tax_id')->nullable();
            $table->char('country', 2);
            $table->char('base_currency', 3);
            $table->smallInteger('fiscal_year_start_month');
            $table->jsonb('address')->default('{}');
            $table->string('timezone');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("alter table companies add constraint companies_country_check check (country in ('KE', 'CD'))");
        DB::statement('alter table companies add constraint companies_fiscal_year_start_month_check check (fiscal_year_start_month between 1 and 12)');

        Rls::enable('companies');
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
