<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TEN-01, TEN-02: tenants, isolated by RLS on their own id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            $table->enum('default_locale', ['en', 'fr'])->default('en');
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();
        });

        Rls::enableOnKey('tenants');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
