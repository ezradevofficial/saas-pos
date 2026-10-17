<?php

namespace Tests\Feature\Core\Platform;

use App\Core\CountryPacks\Jobs\PropagatePackToTenant;
use App\Core\CountryPacks\Models\CountryPack;
use App\Core\CountryPacks\Models\CountryPackRelease;
use App\Core\CountryPacks\PackDocuments;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformDatabase;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Workflow\Calendar\PublicHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Modules\HR\Support\HrPack;
use Modules\Payroll\Statutory\PayrollPack;
use Modules\Payroll\Tests\Concerns\BuildsPayroll;
use Modules\Payroll\Tests\Concerns\FixturePacks;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * ADM-04 (docs/modules/platform-admin.md, ADR 022): staff load, diff,
 * validate, approve and publish country pack releases; publishing takes
 * two people; a release takes over from its effective date; a payroll
 * run uses the published release. Fixture figures are fictional
 * (FixturePacks), never real rates.
 */
class PackReleasesTest extends TestCase
{
    use BuildsPayroll, CreatesPlatformStaff, RefreshTenantDatabase, ResetsPlatformTables;

    private array $loader;

    private array $approver;

    private array $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->loader = $this->staffBearer($this->staff(PlatformUser::ROLE_ENGINEERING));
        $this->approver = $this->staffBearer($this->staff(PlatformUser::ROLE_COMPLIANCE));
        $this->publisher = $this->staffBearer($this->staff(PlatformUser::ROLE_COMPLIANCE));
    }

    private static function file(string $country, string $kind): array
    {
        return json_decode((string) file_get_contents(PackDocuments::file($country, $kind)), true, 64, JSON_THROW_ON_ERROR);
    }

    private function load(string $kind, array $content, string $from = '2026-11-01', string $country = 'KE'): array
    {
        return $this->postJson('/api/v1/platform/packs', ['country' => $country, 'kind' => $kind, 'content' => $content, 'effective_from' => $from, 'notes' => 'Test release'], $this->loader)
            ->assertCreated()->json('data');
    }

    private function approveAndPublish(string $id): void
    {
        $this->postJson("/api/v1/platform/packs/{$id}/approve", [], $this->approver)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/platform/packs/{$id}/publish", [], $this->publisher)->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_validation_finds_schema_errors_overlaps_and_missing_values_and_blocks_approval(): void
    {
        $pack = FixturePacks::ke();

        // An overlap: a second PAYE period starting inside the first open-ended one.
        $overlapping = $pack;
        $overlapping['components'][0]['periods'][] = [...$overlapping['components'][0]['periods'][0], 'effective_from' => '2026-06-01'];
        $bad = $this->load('payroll', $overlapping);
        $this->assertNotEmpty(array_filter($bad['validation']['errors'], fn ($e) => str_contains($e, 'overlap')));
        $this->postJson("/api/v1/platform/packs/{$bad['id']}/approve", [], $this->approver)->assertStatus(422)->assertJsonPath('code', 'pack_release_invalid');

        // A missing required value: confirmed, but PAYE has no bands.
        $missing = $pack;
        $missing['components'][0]['periods'][0]['bands'] = null;
        $this->assertNotEmpty(array_filter($this->load('payroll', $missing)['validation']['errors'], fn ($e) => str_contains($e, 'bands')));

        // The wrong country, and a broken schema.
        $this->assertNotEmpty($this->load('payroll', [...$pack, 'code' => 'CD'])['validation']['errors']);
        $this->assertNotEmpty($this->load('payroll', ['code' => 'KE'])['validation']['errors']);
        $this->assertNotEmpty($this->load('tax', [...self::file('KE', 'tax'), 'tax_codes' => [['code' => 'VAT_STD', 'kind' => 'vat', 'rate' => 16.5, 'needs_confirmation' => false, 'effective_from' => '2026-01-01', 'effective_to' => null, 'fiscal_code' => null]]])['validation']['errors'], 'a float rate is refused (PackFile)');

        // The shipped files are valid: their empty figures are warnings, not errors.
        foreach (array_keys(PackDocuments::KINDS) as $kind) {
            $shipped = $this->load($kind, self::file('KE', $kind));
            $this->assertSame([], $shipped['validation']['errors'], "the shipped KE {$kind} pack validates");
            $this->assertSame([], [...$shipped['diff']['added'], ...$shipped['diff']['removed'], ...$shipped['diff']['changed']], "the shipped KE {$kind} pack has no diff with itself");
        }

        $this->assertNotEmpty($this->load('payroll', self::file('KE', 'payroll'))['validation']['warnings']);
    }

    public function test_publishing_takes_two_people_and_every_step_is_audited(): void
    {
        $release = $this->load('payroll', FixturePacks::ke());
        $this->assertContains('components:paye', $release['diff']['changed']);
        $id = $release['id'];

        // The loader cannot approve their own release; engineering cannot publish at all.
        $loaderStaff = PlatformUser::query()->where('role', PlatformUser::ROLE_ENGINEERING)->firstOrFail();
        $this->postJson("/api/v1/platform/packs/{$id}/approve", [], $this->loader)->assertForbidden()->assertJsonPath('code', 'pack_release_own');
        $this->postJson("/api/v1/platform/packs/{$id}/publish", [], $this->approver)->assertStatus(422)->assertJsonPath('code', 'pack_release_not_approved');
        $this->postJson("/api/v1/platform/packs/{$id}/approve", [], $this->approver)->assertOk();
        // The approver cannot publish what they approved.
        $this->postJson("/api/v1/platform/packs/{$id}/publish", [], $this->approver)->assertForbidden()->assertJsonPath('code', 'pack_release_own_approval');
        $this->postJson("/api/v1/platform/packs/{$id}/publish", [], $this->loader)->assertForbidden();
        $this->postJson("/api/v1/platform/packs/{$id}/publish", [], $this->publisher)->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertNotNull($loaderStaff);

        $actions = PlatformDatabase::connection()->table('platform_audit_logs')->where('subject_id', $id)->orderBy('seq')->pluck('action')->all();
        $this->assertSame(['platform.pack.load', 'platform.pack.approve', 'platform.pack.publish'], $actions);

        // Support, sales and finance see no pack routes; a tenant token never reaches them.
        foreach ([PlatformUser::ROLE_SUPPORT, PlatformUser::ROLE_SALES, PlatformUser::ROLE_FINANCE] as $role) {
            $this->getJson('/api/v1/platform/packs', $this->staffBearer($this->staff($role)))->assertForbidden();
        }

        // A published release never changes (trigger, every role).
        $this->expectException(QueryException::class);
        CountryPackRelease::query()->whereKey($id)->toBase()->update(['content' => json_encode(['code' => 'KE'])]);
    }

    public function test_a_release_takes_over_from_its_effective_date(): void
    {
        $release = $this->load('hr', [...self::file('KE', 'hr'), 'statutory_leave_types' => [
            ['key' => 'annual', 'accrual' => 'annual', 'count_basis' => 'working_days', 'paid' => true, 'days' => 21, 'needs_confirmation' => false, 'effective_from' => '2026-12-01', 'effective_to' => null],
        ]], from: '2026-12-01');
        $this->approveAndPublish($release['id']);

        $documents = app(PackDocuments::class);
        $this->assertSame('shipped', $documents->current('KE', 'hr')['source'], 'not before its date');
        $this->getJson('/api/v1/platform/packs/current', $this->approver)->assertOk()->assertJsonFragment(['country' => 'KE', 'kind' => 'hr', 'source' => 'shipped']);

        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:00:01', 'UTC'));
        $this->assertSame(['source' => 'release', 'version' => $release['version']], array_intersect_key($documents->current('KE', 'hr'), ['source' => true, 'version' => true]));
        $this->assertSame(21, app(HrPack::class)->statutoryLeaveTypes('KE', CarbonImmutable::now())[0]['days']);
    }

    public function test_a_payroll_run_uses_the_published_release(): void
    {
        $this->setUpPayroll();
        // No injected pack: the run reads what the platform published.
        $this->app->instance(PayrollPack::class, new PayrollPack);
        $release = $this->load('payroll', FixturePacks::ke());
        $this->approveAndPublish($release['id']);

        $this->payrollEmployee('5000000');
        $run = $this->calculatedRun();
        $this->getJson("/api/v1/payroll/runs/{$run}", $this->headersFor($this->officer))->assertOk()
            ->assertJsonPath('data.pack.source', 'release')->assertJsonPath('data.pack.version', $release['version'])
            ->assertJsonPath('data.pack.sha256', $release['content_hash']);
        $this->assertNotEmpty($this->lines($run));
    }

    public function test_tax_and_holiday_releases_are_written_into_the_pack_tables_on_their_date(): void
    {
        $this->setUpOrganisation();
        // A company made through the API gets the KE pack's codes (rate needed).
        $company = $this->postJson('/api/v1/companies', ['name' => 'Acme Coast', 'country' => 'KE'], $this->headersFor())->assertCreated()->json('data.id');
        // Review H3: a suspended tenant gets the release too (it is current when reactivated).
        $this->inTenant(fn () => Tenant::query()->whereKey($this->owner->tenant_id)->update(['status' => 'suspended']));
        $tax = self::file('KE', 'tax');
        // A fictional figure for the test only, never a real rate.
        $tax['tax_codes'][0] = [...$tax['tax_codes'][0], 'rate' => '11', 'needs_confirmation' => false];
        $before = CountryPack::query()->where('code', 'KE')->max('version');

        $release = $this->load('tax', $tax, from: '2026-11-15');
        $this->approveAndPublish($release['id']);
        $this->assertSame($before, CountryPack::query()->where('code', 'KE')->max('version'), 'nothing is applied before its date');

        $this->travelTo(CarbonImmutable::parse('2026-11-15 00:20:00', 'UTC'));
        $this->organisationTokens = [];
        $this->artisan('country-packs:apply-releases')->assertSuccessful();
        $this->assertSame($before + 1, CountryPack::query()->where('code', 'KE')->max('version'));
        $rate = $this->inTenant(fn () => TaxCode::query()->where('company_id', $company)->where('pack_code', 'VAT_STD')->firstOrFail()->rates()->orderBy('effective_from')->first());
        $this->assertSame('11.0000', (string) $rate->rate, 'the tenant\'s pack code took the published rate');
        $this->assertNotNull(CountryPackRelease::query()->findOrFail($release['id'])->applied_at);
        // Applying again changes nothing.
        $this->artisan('country-packs:apply-releases')->assertSuccessful();
        $this->assertSame($before + 1, CountryPack::query()->where('code', 'KE')->max('version'));

        // The deploy command reads the release, not the file: no new version.
        $this->artisan('country-packs:publish', ['code' => 'KE'])->assertSuccessful();
        $this->assertSame($before + 1, CountryPack::query()->where('code', 'KE')->max('version'));

        // Staff tokens last 8 hours: sign the staff in again.
        $this->loader = $this->staffBearer(PlatformUser::query()->where('role', PlatformUser::ROLE_ENGINEERING)->firstOrFail());
        [$first, $second] = PlatformUser::query()->where('role', PlatformUser::ROLE_COMPLIANCE)->orderBy('created_at')->get()->all();
        [$this->approver, $this->publisher] = [$this->staffBearer($first), $this->staffBearer($second)];
        $holidays = [...self::file('KE', 'holidays'), 'holidays' => [['key' => 'new_year', 'month' => 1, 'day' => 1]]];
        $this->approveAndPublish($this->load('holidays', $holidays, from: '2026-11-15')['id']);
        $this->assertSame(['new_year'], PublicHoliday::query()->where('country', 'KE')->pluck('key')->all());
    }

    /** Review M4: one queued job per tenant (suspended ones included), so a failure is isolated and retried. */
    public function test_a_tax_release_queues_one_propagation_job_per_tenant(): void
    {
        $this->setUpOrganisation();
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => Tenant::query()->whereKey($other['user']->tenant_id)->update(['status' => 'suspended']));
        Bus::fake([PropagatePackToTenant::class]);
        $tax = self::file('KE', 'tax');
        $tax['tax_codes'][0] = [...$tax['tax_codes'][0], 'rate' => '11', 'needs_confirmation' => false];

        $this->approveAndPublish($this->load('tax', $tax, from: '2026-11-02')['id']);

        Bus::assertDispatched(PropagatePackToTenant::class, fn ($job) => $job->tenantId === $this->owner->tenant_id);
        Bus::assertDispatched(PropagatePackToTenant::class, fn ($job) => $job->tenantId === $other['user']->tenant_id);
        $this->assertSame(1, collect(Bus::dispatched(PropagatePackToTenant::class))->filter(fn ($job) => $job->tenantId === $this->owner->tenant_id)->count());
    }

    /** Review L8: the runtime role reads published releases only. */
    public function test_the_runtime_role_sees_published_releases_only(): void
    {
        $draft = $this->load('hr', self::file('KE', 'hr'));
        $published = $this->load('hr', self::file('KE', 'hr'));
        $this->approveAndPublish($published['id']);

        $this->assertSame([$published['id']], CountryPackRelease::on('pgsql')->pluck('id')->all());
        $this->assertSame(2, CountryPackRelease::query()->whereIn('id', [$draft['id'], $published['id']])->count(), 'the platform role sees them all');
    }
}
