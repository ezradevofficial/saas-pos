<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TEN-05: locations (outlet, warehouse, store, office) of a branch.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('name');
            $table->enum('type', ['outlet', 'warehouse', 'store', 'office']);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        Rls::enable('locations');
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
