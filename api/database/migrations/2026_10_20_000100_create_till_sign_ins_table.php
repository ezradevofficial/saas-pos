<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AUTH-07: till sign-ins the server checked online (POST pos/pin/verify
        // with a session_id). A device's sign-in attestation naming one of these
        // sessions is `online`; any other is the device's own claim.
        Schema::create('till_sign_ins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('session_id');
            // As the device stated it (its idea of the server's clock), when given.
            $table->string('signed_in_at', 40)->nullable();
            $table->timestampTz('verified_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'device_id', 'session_id']);
        });

        Rls::enable('till_sign_ins');
    }

    public function down(): void
    {
        Schema::dropIfExists('till_sign_ins');
    }
};
