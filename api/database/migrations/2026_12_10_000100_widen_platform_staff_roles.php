<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADM-07 (docs/modules/platform-admin.md): the staff roles of the
// super-admin console. `billing` becomes `finance`; `owner` and `support`
// stay. Permissions per role are code (PlatformUser::PERMISSIONS).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table platform_users drop constraint platform_users_role_check');
        DB::statement("update platform_users set role = 'finance' where role = 'billing'");
        DB::statement("alter table platform_users add constraint platform_users_role_check check (role in ('owner', 'support', 'sales', 'finance', 'compliance', 'engineering'))");
    }

    public function down(): void
    {
        DB::statement('alter table platform_users drop constraint platform_users_role_check');
        DB::statement("update platform_users set role = 'billing' where role = 'finance'");
        DB::statement("update platform_users set role = 'support' where role in ('sales', 'compliance', 'engineering')");
        DB::statement("alter table platform_users add constraint platform_users_role_check check (role in ('owner', 'billing', 'support'))");
    }
};
