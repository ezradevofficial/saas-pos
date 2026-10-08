<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MD-04: each company's payment methods (cash per currency, mobile money
// wallets, card, credit, voucher, points, bank transfer) in the order the
// till shows them. Non-secret provider settings in `settings`; provider
// credentials encrypted by the application in `secrets`, never returned.
// MD-05: departments, cost centres and projects per company, each a tree
// within its company, with an owner (department head or cost-centre owner,
// APR-02). Codes are case-insensitive and unique among active rows.
return new class extends Migration
{
    public const DIMENSIONS = ['departments', 'cost_centres', 'projects'];

    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('name', 100);
            $table->char('currency', 3)->nullable();
            $table->string('provider', 30)->nullable();
            $table->jsonb('settings')->default('{}');
            // Encrypted JSON (Laravel `encrypted:array`): provider credentials.
            $table->text('secrets')->nullable();
            $table->boolean('active')->default(false);
            $table->integer('position');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['company_id', 'position']);
            // NFR-04: POS downloads changes by updated_at cursor.
            $table->index(['tenant_id', 'updated_at', 'id']);
        });

        DB::statement("alter table payment_methods add constraint payment_methods_type_check check (type in ('cash', 'mobile_money', 'card', 'credit', 'voucher', 'points', 'bank_transfer'))");
        DB::statement("alter table payment_methods add constraint payment_methods_cash_currency_check check (type <> 'cash' or currency is not null)");
        DB::statement("alter table payment_methods add constraint payment_methods_provider_check check ((type in ('mobile_money', 'card')) = (provider is not null))");
        DB::statement('alter table payment_methods add constraint payment_methods_position_check check (position >= 1)');
        Rls::enable('payment_methods');

        foreach (self::DIMENSIONS as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->tenantId();
                $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
                $table->string('code', 30);
                $table->string('name', 255);
                $table->uuid('parent_id')->nullable();
                // APR-02: department head or cost-centre owner.
                $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestampTz('archived_at')->nullable();
                $table->timestampsTz();

                // Target of the parent's composite key: a parent is in the same company.
                $table->unique(['id', 'company_id']);
                $table->index('parent_id');
                $table->index(['tenant_id', 'updated_at', 'id']);
            });

            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->foreign(['parent_id', 'company_id'])->references(['id', 'company_id'])->on($name)->restrictOnDelete();
            });

            DB::statement("alter table {$name} alter column code type citext");
            DB::statement("alter table {$name} add constraint {$name}_parent_check check (parent_id is null or parent_id <> id)");
            DB::statement("create unique index {$name}_company_code_active_unique on {$name} (company_id, code) where archived_at is null");
            Rls::enable($name);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::DIMENSIONS) as $name) {
            Schema::dropIfExists($name);
        }

        Schema::dropIfExists('payment_methods');
    }
};
