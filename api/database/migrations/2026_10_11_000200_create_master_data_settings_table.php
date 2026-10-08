<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// TEN-08: per data type, whether master data is shared across the group's
// companies or kept per company. No row: shared (the default).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_data_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('data_type', 20);
            $table->string('mode', 20);
            $table->timestampTz('changed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'data_type']);
        });

        DB::statement("alter table master_data_settings add constraint master_data_settings_type_check check (data_type in ('items', 'customers', 'suppliers', 'employees'))");
        DB::statement("alter table master_data_settings add constraint master_data_settings_mode_check check (mode in ('shared', 'per_company'))");

        Rls::enable('master_data_settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('master_data_settings');
    }
};
