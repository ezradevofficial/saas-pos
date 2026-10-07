<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RBAC-08: which optional modules a tenant has active. `core` is always
// active and has no row.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('module');
            $table->enum('status', ['active', 'inactive']);
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'module']);
        });

        Rls::enable('tenant_modules');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
    }
};
