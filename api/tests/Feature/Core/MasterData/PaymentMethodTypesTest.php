<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * MD-04: the database's payment method type check accepts exactly the types the
 * code knows, so a module adding a type (room_charge, fuel_voucher,
 * salon_credit) can never drop another module's type.
 */
class PaymentMethodTypesTest extends TestCase
{
    use RefreshTenantDatabase;

    public function test_the_type_check_accepts_every_known_type_and_nothing_else(): void
    {
        $definition = (string) DB::connection('pgsql_owner')->scalar(
            "select pg_get_constraintdef(oid) from pg_constraint where conname = 'payment_methods_type_check'"
        );
        preg_match_all("/'([a-z_]+)'/", $definition, $matches);
        $allowed = $matches[1];
        sort($allowed);
        $types = PaymentMethod::TYPES;
        sort($types);

        $this->assertSame($types, $allowed);
    }
}
