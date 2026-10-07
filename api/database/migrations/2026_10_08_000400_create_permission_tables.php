<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// RBAC-01, RBAC-02, RBAC-03: Spatie's tables, adapted. The permission
// catalogue is global; roles and their permission links belong to a tenant
// under row-level security. Spatie's model_has_roles and
// model_has_permissions are not created: assignments are scoped and live in
// role_assignments (RBAC-04).
return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');

        throw_if(empty($tableNames), 'Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.');

        // Global catalogue, written by `permissions:sync` (no tenant_id).
        Schema::create($tableNames['permissions'], function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('guard_name');
            $table->string('module');
            $table->string('resource');
            $table->string('action');
            $table->timestampsTz();

            $table->unique(['name', 'guard_name']);
            $table->index('module');
        });

        Schema::create($tableNames['roles'], function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('name');
            $table->string('guard_name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->string('template_key')->nullable();
            $table->boolean('is_owner')->default(false);
            $table->boolean('requires_two_factor')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name', 'guard_name']);
            // Target of the composite foreign keys below: a link can only
            // point at a role of its own tenant.
            $table->unique(['tenant_id', 'id']);
        });

        DB::statement("create unique index roles_tenant_id_template_key_unique on {$tableNames['roles']} (tenant_id, template_key) where template_key is not null");
        Rls::enable($tableNames['roles']);

        Schema::create($tableNames['role_has_permissions'], function (Blueprint $table) use ($tableNames) {
            $table->foreignUuid('permission_id')->constrained($tableNames['permissions'])->cascadeOnDelete();
            $table->uuid('role_id');
            // Defaults to the session tenant, so Spatie's own inserts need no change.
            $table->tenantId();

            $table->foreign(['tenant_id', 'role_id'])->references(['tenant_id', 'id'])->on($tableNames['roles'])->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
            $table->index('role_id');
        });

        Rls::enable($tableNames['role_has_permissions']);

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');

        Schema::dropIfExists($tableNames['role_has_permissions']);
        Schema::dropIfExists($tableNames['roles']);
        Schema::dropIfExists($tableNames['permissions']);
    }
};
