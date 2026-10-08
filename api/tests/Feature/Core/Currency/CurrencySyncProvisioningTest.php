<?php

namespace Tests\Feature\Core\Currency;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Identity\Models\VerificationChallenge;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * CUR-01: `currencies:sync` gives tenants created before CUR-01 their
 * companies' currencies, each tenant under row-level security, and never
 * overwrites a tenant's own settings. The sync lists tenants as the schema
 * owner, which cannot see uncommitted rows, so this test commits and the
 * next test migrates afresh.
 */
class CurrencySyncProvisioningTest extends TestCase
{
    use CreatesIdentities, RefreshTenantDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_the_seeder_provisions_missing_tenant_currencies_under_row_level_security(): void
    {
        Notification::fake();

        $challengeId = $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => $this->password,
            'country' => 'CD',
            'locale' => 'fr',
            'business_name' => 'Kin Stores',
        ])->assertCreated()->json('challenge_id');
        $tenantId = VerificationChallenge::findOrFail($challengeId)->tenant_id;

        // As if the tenant predated CUR-01 for CDF, and had changed USD.
        $this->asTenant($tenantId, function () {
            TenantCurrency::where('code', 'CDF')->delete();
            $usd = TenantCurrency::where('code', 'USD')->sole();
            $usd->cash_rounding_minor = 5;
            $usd->save();
        });

        $onOwner = [];
        DB::listen(function (QueryExecuted $query) use (&$onOwner) {
            if ($query->connectionName === 'pgsql_owner' && preg_match('/"(tenant_currencies|companies|audit_logs)"/', $query->sql)) {
                $onOwner[] = $query->sql;
            }
        });

        $this->assertSame(0, Artisan::call('db:seed', [
            '--class' => 'CurrencyCatalogueSeeder',
            '--database' => 'pgsql_owner',
            '--force' => true,
        ]));

        $this->assertSame([], $onOwner);
        $this->assertSame('pgsql', DB::getDefaultConnection());

        $this->asTenant($tenantId, function () use ($tenantId) {
            $this->assertSame(
                [['CDF', 0, 50], ['USD', 2, 5]],
                TenantCurrency::orderBy('code')->get()->map(fn ($c) => [$c->code, $c->decimals, $c->cash_rounding_minor])->all(),
            );
            $entry = AuditEntry::where('action', 'core.currency.create')->latest('seq')->first();
            $this->assertSame($tenantId, $entry->tenant_id);
            $this->assertNull($entry->user_id);
        });
    }
}
