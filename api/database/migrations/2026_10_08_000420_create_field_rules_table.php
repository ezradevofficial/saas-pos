<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RBAC-05: per-role field visibility (hidden or read-only) on a resource.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('role_id');
            $table->string('resource');
            $table->string('field');
            $table->enum('mode', ['hidden', 'readonly']);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'role_id'])->references(['tenant_id', 'id'])->on('roles')->cascadeOnDelete();
            $table->unique(['role_id', 'resource', 'field']);
            $table->index(['tenant_id', 'resource']);
        });

        Rls::enable('field_rules');
    }

    public function down(): void
    {
        Schema::dropIfExists('field_rules');
    }
};
