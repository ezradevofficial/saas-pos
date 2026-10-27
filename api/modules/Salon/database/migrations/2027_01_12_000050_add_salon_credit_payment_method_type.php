<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MD-04, SAL-17, SAL-18: a payment method of type `salon_credit` pays part of a
// POS sale with prepaid salon value (an appointment's deposit, a package
// session). It is provided by the Salon module through the core tender seam,
// as Hotel provides `room_charge` and Loyalty `credit`. The type is core
// data; without the module no till offers it.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table payment_methods drop constraint payment_methods_type_check');
        DB::statement("alter table payment_methods add constraint payment_methods_type_check check (type in ('cash', 'mobile_money', 'card', 'credit', 'voucher', 'points', 'bank_transfer', 'room_charge', 'salon_credit'))");
    }

    public function down(): void
    {
        DB::statement('alter table payment_methods drop constraint payment_methods_type_check');
        DB::statement("alter table payment_methods add constraint payment_methods_type_check check (type in ('cash', 'mobile_money', 'card', 'credit', 'voucher', 'points', 'bank_transfer', 'room_charge'))");
    }
};
