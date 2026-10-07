<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// RBAC-06: per-role numeric limits (max discount, refund, approval amount,
// credit limit override).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limit_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('role_id');
            $table->string('key');
            $table->decimal('value', 18, 4);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'role_id'])->references(['tenant_id', 'id'])->on('roles')->cascadeOnDelete();
            $table->unique(['role_id', 'key']);
        });

        DB::statement("alter table limit_rules add constraint limit_rules_key_check check (key in ('max_discount_percent', 'max_refund_amount', 'max_approval_amount', 'credit_limit_override'))");
        DB::statement('alter table limit_rules add constraint limit_rules_value_check check (value >= 0)');
        Rls::enable('limit_rules');
    }

    public function down(): void
    {
        Schema::dropIfExists('limit_rules');
    }
};
