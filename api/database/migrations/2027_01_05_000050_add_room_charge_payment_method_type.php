<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MD-04, HOT-22: a payment method of type `room_charge` pays a POS sale to a
// guest's room (provided by the Hotel module through the core tender seam,
// as Loyalty provides `credit`). The type is core data; without the module
// no till offers it.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table payment_methods drop constraint payment_methods_type_check');
        DB::statement("alter table payment_methods add constraint payment_methods_type_check check (type in ('cash', 'mobile_money', 'card', 'credit', 'voucher', 'points', 'bank_transfer', 'room_charge'))");
    }

    public function down(): void
    {
        DB::statement('alter table payment_methods drop constraint payment_methods_type_check');
        DB::statement("alter table payment_methods add constraint payment_methods_type_check check (type in ('cash', 'mobile_money', 'card', 'credit', 'voucher', 'points', 'bank_transfer'))");
    }
};
