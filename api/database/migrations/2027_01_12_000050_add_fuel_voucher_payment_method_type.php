<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MD-04, FUEL-21: a payment method of type `fuel_voucher` pays a POS sale with a
// prepaid fuel voucher (provided by the Fuel station module through the core
// tender seam, as the Hotel module provides `room_charge`). The type is core
// data; without the module no till offers it.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table payment_methods drop constraint payment_methods_type_check');
        DB::statement("alter table payment_methods add constraint payment_methods_type_check check (type in ('cash', 'mobile_money', 'card', 'credit', 'voucher', 'points', 'bank_transfer', 'room_charge', 'fuel_voucher'))");
    }

    public function down(): void
    {
        DB::statement('alter table payment_methods drop constraint payment_methods_type_check');
        DB::statement("alter table payment_methods add constraint payment_methods_type_check check (type in ('cash', 'mobile_money', 'card', 'credit', 'voucher', 'points', 'bank_transfer', 'room_charge'))");
    }
};
