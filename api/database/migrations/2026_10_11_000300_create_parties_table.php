<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MD-01: one party record per person or organisation, with roles
// (customer, supplier, contact, employee link). Shared across the group
// (company_id null) or one company's, following the sharing mode of its
// roles' data types (TEN-08). Phones are E.164, emails lower case.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            // Null: shared across the group's companies.
            $table->foreignUuid('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('tax_id', 50)->nullable();
            $table->jsonb('phones')->default('[]');
            $table->jsonb('emails')->default('[]');
            $table->jsonb('addresses')->default('[]');
            $table->char('currency', 3)->nullable();
            $table->integer('payment_terms_days')->nullable();
            $table->money('credit_limit', nullable: true);
            $table->foreignUuid('price_list_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('credit_limit_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index('company_id');
        });

        DB::statement("alter table parties add column tags text[] not null default '{}'");
        DB::statement('alter table parties add column roles text[] not null');

        DB::statement("alter table parties add constraint parties_kind_check check (kind in ('person', 'organisation'))");
        DB::statement("alter table parties add constraint parties_roles_check check (cardinality(roles) > 0 and roles <@ array['customer', 'supplier', 'contact', 'employee_link']::text[])");
        DB::statement('alter table parties add constraint parties_payment_terms_check check (payment_terms_days is null or payment_terms_days >= 0)');
        DB::statement('alter table parties add constraint parties_credit_limit_check check ((credit_limit_minor is null) = (credit_limit_currency is null) and (credit_limit_minor is null or credit_limit_minor >= 0))');
        DB::statement("alter table parties add constraint parties_json_arrays_check check (jsonb_typeof(phones) = 'array' and jsonb_typeof(emails) = 'array' and jsonb_typeof(addresses) = 'array')");

        // `?role=` and `?tag=` (array contains), phone matches (MD-06),
        // name search and similarity (pg_trgm), tax ID matches.
        DB::statement('create index parties_roles_gin on parties using gin (roles)');
        DB::statement('create index parties_tags_gin on parties using gin (tags)');
        DB::statement('create index parties_phones_gin on parties using gin (phones jsonb_path_ops)');
        DB::statement('create index parties_name_trgm on parties using gin (name gin_trgm_ops)');
        DB::statement('create index parties_tax_id_index on parties (tenant_id, tax_id) where tax_id is not null');

        Rls::enable('parties');
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
