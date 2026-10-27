<?php

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MD-04: the Fuel station (fuel_voucher) and Salon (salon_credit) migrations each
// redefined the type check without the other's type, so whichever ran last
// refused the other module's payment method. The check now lists every type in
// PaymentMethod::TYPES (PaymentMethodTypesTest keeps them in step).
return new class extends Migration
{
    public function up(): void
    {
        $types = implode(', ', array_map(fn (string $type) => "'{$type}'", PaymentMethod::TYPES));
        DB::statement('alter table payment_methods drop constraint if exists payment_methods_type_check');
        DB::statement("alter table payment_methods add constraint payment_methods_type_check check (type in ({$types}))");
    }

    public function down(): void
    {
        // Forward-only: dropping a type could orphan existing payment methods.
    }
};
