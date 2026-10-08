<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Identity\Models\VerificationChallenge;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * MD-04: `payment-methods:seed-defaults` gives companies that lack them
 * the default payment methods, each inside its own tenant (row-level
 * security), never re-creating an entry a company ever had. The command
 * lists tenants as the schema owner, which cannot see uncommitted rows, so
 * this test commits and the next test migrates afresh.
 */
class SeedDefaultPaymentMethodsCommandTest extends TestCase
{
    use BuildsRbac, RefreshTenantDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    private function signUp(string $email, string $country): string
    {
        Notification::fake();
        $challengeId = $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Owner', 'email' => $email, 'password' => $this->password, 'country' => $country, 'locale' => 'en', 'business_name' => 'Stores',
        ])->assertCreated()->json('challenge_id');

        return VerificationChallenge::findOrFail($challengeId)->tenant_id;
    }

    public function test_missing_defaults_are_added_and_archived_ones_stay_archived(): void
    {
        $kenya = $this->signUp('ke@example.com', 'KE');
        $congo = $this->signUp('cd@example.com', 'CD');
        $this->asTenant($congo, fn () => $this->assertSame(7, PaymentMethod::query()->count()));

        // Kenya archived M-Pesa and never had Airtel Money or cash USD (as for a company created before MD-04).
        $this->asTenant($kenya, function () {
            $this->assertTrue(PaymentMethod::query()->where('currency', 'USD')->sole()->active, 'cash seeded at creation is on');
            PaymentMethod::query()->where('provider', 'mpesa_ke')->sole()->archive();
            DB::table('payment_methods')->where('provider', 'airtel_ke')->delete();
            DB::table('payment_methods')->where('type', 'cash')->where('currency', 'USD')->delete();
        });

        $this->assertSame(0, Artisan::call('payment-methods:seed-defaults'));
        $this->assertMatchesRegularExpression('/^2 payment methods created in 2 companies/', Artisan::output());

        $this->asTenant($kenya, function () {
            $this->assertSame(5, PaymentMethod::query()->count());
            $this->assertNotNull(PaymentMethod::query()->where('provider', 'mpesa_ke')->sole()->archived_at);
            // A back-fill adds cash switched off: it never opens a new till on its own (MD-04).
            $usd = PaymentMethod::query()->where('type', 'cash')->where('currency', 'USD')->sole();
            $this->assertFalse($usd->active);
            $this->assertSame(6, $usd->position);
            $airtel = PaymentMethod::query()->where('provider', 'airtel_ke')->sole();
            $this->assertFalse($airtel->active);
            $this->assertSame(7, $airtel->position);
            $this->assertTrue(PaymentMethod::query()->where('currency', 'KES')->sole()->active);
        });
        $this->asTenant($congo, fn () => $this->assertSame(7, PaymentMethod::query()->count()));

        Artisan::call('payment-methods:seed-defaults');
        $this->assertMatchesRegularExpression('/^0 payment methods created/', Artisan::output());
    }
}
