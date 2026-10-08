<?php

namespace Tests\Feature\Core\CountryPacks;

use App\Core\Audit\AuditEntry;
use App\Core\Audit\Auditor;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\MasterData\Taxes\TaxRates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * CP-02, CP-03 (ADR 007): a new pack version reaches the rates of codes
 * already copied into existing tenants, unless the tenant entered a rate of
 * its own. Each tenant is updated under row-level security; the owner
 * connection only lists tenant ids. The publisher lists tenants as the
 * schema owner, which cannot see uncommitted rows, so this test commits and
 * the next test migrates afresh. The rates are synthetic (12.5, 10), never
 * real VAT figures.
 */
class PackPropagationTest extends TestCase
{
    use CreatesIdentities, RefreshTenantDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    private function signUp(string $email): string
    {
        $challengeId = $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Owner',
            'email' => $email,
            'password' => $this->password,
            'country' => 'KE',
            'locale' => 'en',
            'business_name' => 'Shop '.$email,
        ])->assertCreated()->json('challenge_id');

        return VerificationChallenge::findOrFail($challengeId)->tenant_id;
    }

    /** The shipped KE pack with VAT_STD confirmed at a synthetic 12.5 from 2026-07-01. */
    private function versionTwo(): string
    {
        $data = json_decode(file_get_contents(base_path('country-packs/KE/pack.json')), true);
        $periods = [];

        foreach ($data['tax_codes'] as $row) {
            if ($row['code'] !== 'VAT_STD') {
                $periods[] = $row;

                continue;
            }

            $periods[] = ['effective_to' => '2026-06-30'] + $row;
            $periods[] = ['rate' => '12.5', 'needs_confirmation' => false, 'effective_from' => '2026-07-01'] + $row;
        }

        $data['tax_codes'] = $periods;
        $path = tempnam(sys_get_temp_dir(), 'pack');
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    /** @return list<array{0: ?string, 1: string, 2: ?string, 3: bool, 4: string}> */
    private function rates(string $tenantId, string $code): array
    {
        return $this->asTenant($tenantId, fn () => TaxRate::query()
            ->where('tax_code_id', TaxCode::where('code', $code)->sole()->id)
            ->orderBy('effective_from')->get()
            ->map(fn (TaxRate $r) => [$r->rate, $r->effective_from->toDateString(), $r->effective_to?->toDateString(), $r->needs_confirmation, $r->source])
            ->all());
    }

    public function test_a_new_pack_version_reaches_untouched_codes_and_never_tenant_entered_rates(): void
    {
        Notification::fake();

        $untouched = $this->signUp('untouched@example.com');
        $own = $this->signUp('own@example.com');

        // This tenant entered its own standard rate.
        $this->asTenant($own, fn () => app(TaxRates::class)->add(TaxCode::where('code', 'VAT_STD')->sole(), '10', CarbonImmutable::parse('2026-03-01')));
        $ownBefore = $this->rates($own, 'VAT_STD');
        // The untouched tenant renamed its code: the name is its own (MD-02).
        $this->asTenant($untouched, fn () => TaxCode::where('code', 'VAT_STD')->sole()->update(['name' => 'Our VAT']));
        $this->assertSame('tenant', $ownBefore[1][4]);

        $onOwner = [];
        DB::listen(function (QueryExecuted $query) use (&$onOwner) {
            if ($query->connectionName === 'pgsql_owner' && preg_match('/"(tax_codes|tax_rates|companies|audit_logs)"/', $query->sql)) {
                $onOwner[] = $query->sql;
            }
        });

        $file = $this->versionTwo();
        $this->assertSame(0, Artisan::call('country-packs:publish', ['code' => 'KE', '--file' => $file]));
        $output = Artisan::output();
        $this->assertStringContainsString('Published KE version 2', $output);
        $this->assertStringContainsString('1 tax codes updated in 2 tenants; 1 skipped (tenant-entered rates)', $output);

        $this->assertSame([], $onOwner);
        $this->assertSame('pgsql', DB::getDefaultConnection());

        // The untouched company follows the pack from the new period's start date.
        $this->assertSame([
            [null, '2026-01-01', '2026-06-30', true, 'pack'],
            ['12.5000', '2026-07-01', null, false, 'pack'],
        ], $this->rates($untouched, 'VAT_STD'));
        // Propagation touches rates only, never the tenant's name.
        $this->asTenant($untouched, fn () => $this->assertSame('Our VAT', TaxCode::where('code', 'VAT_STD')->sole()->name));
        // Other codes were already equal to the pack.
        $this->assertSame([[null, '2026-01-01', null, true, 'pack']], $this->rates($untouched, 'VAT_WHT'));

        $this->asTenant($untouched, function () use ($untouched) {
            $entry = AuditEntry::where('action', 'core.tax.pack_update')->sole();
            $this->assertSame($untouched, $entry->tenant_id);
            $this->assertNull($entry->user_id);
            $this->assertSame(['KE', 2], [$entry->after['pack'], $entry->after['version']]);
            $this->assertSame(TaxCode::where('code', 'VAT_STD')->sole()->id, $entry->auditable_id);
            $this->assertNull(app(Auditor::class)->verify($untouched));
        });

        // The tenant that entered its own rate is left exactly as it was.
        $this->assertSame($ownBefore, $this->rates($own, 'VAT_STD'));
        $this->asTenant($own, fn () => $this->assertSame(0, AuditEntry::where('action', 'core.tax.pack_update')->count()));

        // Publishing the same content again changes nothing.
        $this->assertSame(0, Artisan::call('country-packs:publish', ['code' => 'KE', '--file' => $file]));
        $output = Artisan::output();
        $this->assertStringContainsString('unchanged: version 2', $output);
        $this->assertStringContainsString('0 tax codes updated in 2 tenants; 1 skipped', $output);
        $this->assertSame('12.5000', $this->rates($untouched, 'VAT_STD')[1][0]);
        $this->assertCount(2, $this->rates($untouched, 'VAT_STD'));
        $this->asTenant($untouched, fn () => $this->assertSame(1, AuditEntry::where('action', 'core.tax.pack_update')->count()));
        $this->assertSame([], $onOwner);
    }
}
