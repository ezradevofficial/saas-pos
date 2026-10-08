<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Identity\Models\VerificationChallenge;
use App\Core\MasterData\Items\Uom;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * MD-02: `uoms:seed-defaults` gives tenants that lack them the default
 * units, each inside its own tenant (row-level security), and is
 * idempotent. The command lists tenants as the schema owner, which cannot
 * see uncommitted rows, so this test commits and the next test migrates
 * afresh.
 */
class SeedDefaultUomsCommandTest extends TestCase
{
    use BuildsRbac, RefreshTenantDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    private function signUp(string $email): string
    {
        Notification::fake();
        $challengeId = $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Owner', 'email' => $email, 'password' => $this->password, 'country' => 'KE', 'locale' => 'en', 'business_name' => 'Stores',
        ])->assertCreated()->json('challenge_id');

        return VerificationChallenge::findOrFail($challengeId)->tenant_id;
    }

    public function test_missing_default_units_are_added_to_every_tenant_once(): void
    {
        $tenantA = $this->signUp('a@example.com');
        $tenantB = $this->signUp('b@example.com');
        // A tenant whose KG unit was archived (as if created before units existed for KG).
        $this->asTenant($tenantA, fn () => Uom::query()->where('code', 'KG')->sole()->archive());

        $this->assertSame(0, Artisan::call('uoms:seed-defaults'));
        $this->assertMatchesRegularExpression('/^1 units created in \d+ tenants/', Artisan::output());

        $this->asTenant($tenantA, function () {
            $this->assertSame(1, Uom::query()->active()->where('code', 'KG')->count());
            $this->assertSame(9, Uom::query()->count());
        });
        $this->asTenant($tenantB, fn () => $this->assertSame(8, Uom::query()->count()));

        Artisan::call('uoms:seed-defaults');
        $this->assertMatchesRegularExpression('/^0 units created/', Artisan::output());
    }
}
