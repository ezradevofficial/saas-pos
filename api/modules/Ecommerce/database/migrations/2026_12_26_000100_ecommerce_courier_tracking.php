<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MKT-04, ECOM-27 (ADR 024): a delivery booked by a courier through the
// public API keeps the courier's tracking: the client's own reference of
// the booking (a booking sent again with it is answered the same), the
// courier's tracking reference and link, and when it was booked. The
// table stays under its forced row-level security (TEN-01).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecom_deliveries', function (Blueprint $table) {
            $table->string('courier_reference', 120)->nullable();
            $table->string('tracking_reference', 120)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->timestampTz('booked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ecom_deliveries', function (Blueprint $table) {
            $table->dropColumn(['courier_reference', 'tracking_reference', 'tracking_url', 'booked_at']);
        });
    }
};
