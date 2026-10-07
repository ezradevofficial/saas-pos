<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TEN-05: POS devices paired to a location.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('name');
            $table->enum('status', ['pending', 'active', 'suspended', 'unpaired'])->default('pending');
            $table->string('pairing_code_hash')->nullable();
            $table->timestampTz('pairing_code_expires_at')->nullable();
            $table->timestampTz('paired_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
        });

        Rls::enable('devices');
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
