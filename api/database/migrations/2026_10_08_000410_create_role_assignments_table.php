<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RBAC-04: a role held by a user at a scope (tenant, company, branch or
// location). For the tenant scope, scope_id is the tenant id.
return new class extends Migration
{
    public function up(): void
    {
        // Target of composite foreign keys: a row can only point at a user
        // of its own tenant.
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('user_id');
            $table->uuid('role_id');
            $table->enum('scope_type', ['tenant', 'company', 'branch', 'location']);
            $table->uuid('scope_id');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'role_id'])->references(['tenant_id', 'id'])->on('roles')->restrictOnDelete();
            $table->unique(['user_id', 'role_id', 'scope_type', 'scope_id']);
            $table->index('role_id');
            $table->index(['scope_type', 'scope_id']);
        });

        Rls::enable('role_assignments');
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'id']);
        });
    }
};
