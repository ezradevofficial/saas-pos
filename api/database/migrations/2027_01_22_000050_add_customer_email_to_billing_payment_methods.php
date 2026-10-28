<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SUB-04: a card saved from Paystack's checkout is charged again with its
// authorization code and the payer's email. The email is kept encrypted
// (like the phone and token of the same table), never returned by the API.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_payment_methods', function (Blueprint $table) {
            $table->text('customer_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_payment_methods', function (Blueprint $table) {
            $table->dropColumn('customer_email');
        });
    }
};
