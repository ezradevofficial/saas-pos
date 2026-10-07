<?php

namespace Tests\Feature\Core\Audit;

use App\Core\Audit\AuditContext;
use App\Core\Audit\AuditEntry;
use App\Core\Audit\Auditor;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantContextMissing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUD-01..AUD-03: every change is logged with who/when/where/what, append-only and hash-chained.
class AuditLogTest extends TestCase
{
    use RefreshTenantDatabase;

    private TenantContext $context;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->a = Tenant::provision(['name' => 'Tenant A']);
        $this->b = Tenant::provision(['name' => 'Tenant B']);
        $this->context->set($this->a->id);
    }

    private function company(string $name = 'A Ltd'): Company
    {
        return Company::create([
            'name' => $name,
            'legal_name' => $name,
            'country' => 'KE',
            'base_currency' => 'KES',
            'fiscal_year_start_month' => 1,
            'address' => ['city' => 'Nairobi'],
            'timezone' => 'Africa/Nairobi',
        ]);
    }

    public function test_creating_a_company_writes_one_create_entry_with_the_new_values(): void
    {
        $company = $this->company();

        $entries = AuditEntry::all();
        $this->assertCount(1, $entries);
        $entry = $entries->first();
        $this->assertSame('core.company.create', $entry->action);
        $this->assertSame('core', $entry->module);
        $this->assertSame($company->getMorphClass(), $entry->auditable_type);
        $this->assertSame($company->id, $entry->auditable_id);
        $this->assertSame($this->a->id, $entry->tenant_id);
        $this->assertSame(1, $entry->seq);
        $this->assertNull($entry->before);
        $this->assertSame('A Ltd', $entry->after['name']);
        $this->assertSame(['city' => 'Nairobi'], $entry->after['address']);
        $this->assertArrayNotHasKey('created_at', $entry->after);
        $this->assertArrayNotHasKey('updated_at', $entry->after);
        $this->assertNotNull($entry->occurred_at);
    }

    public function test_updating_the_name_writes_only_the_name_before_and_after(): void
    {
        $company = $this->company();
        $company->update(['name' => 'A Holdings']);

        $entry = AuditEntry::where('action', 'core.company.update')->sole();
        $this->assertSame(['name' => 'A Ltd'], $entry->before);
        $this->assertSame(['name' => 'A Holdings'], $entry->after);
    }

    public function test_saving_without_real_changes_writes_nothing(): void
    {
        $company = $this->company();
        $company->touch();
        $company->save();

        $this->assertSame(1, AuditEntry::count());
    }

    public function test_archive_and_restore_have_their_own_actions(): void
    {
        $branch = Branch::create(['company_id' => $this->company()->id, 'name' => 'Westlands', 'code' => 'WL', 'address' => []]);

        $branch->archive();
        $branch->restore();

        $this->assertSame(
            ['core.company.create', 'core.branch.create', 'core.branch.archive', 'core.branch.restore'],
            AuditEntry::orderBy('seq')->pluck('action')->all(),
        );
        $archive = AuditEntry::where('action', 'core.branch.archive')->sole();
        $this->assertSame(['archived_at'], array_keys($archive->after));
        $this->assertNull($archive->before['archived_at']);
        $this->assertNotNull($archive->after['archived_at']);
    }

    public function test_location_and_device_are_audited_without_hidden_fields(): void
    {
        $branch = Branch::create(['company_id' => $this->company()->id, 'name' => 'Westlands', 'code' => 'WL', 'address' => []]);
        $location = Location::create(['branch_id' => $branch->id, 'name' => 'Front shop', 'type' => 'outlet']);
        $device = Device::create(['location_id' => $location->id, 'name' => 'Till 1']);

        $device->forceFill(['pairing_code_hash' => 'secret-hash', 'name' => 'Till 2'])->save();

        $this->assertSame(1, AuditEntry::where('action', 'core.location.create')->count());
        $this->assertSame(1, AuditEntry::where('action', 'core.device.create')->count());
        $update = AuditEntry::where('action', 'core.device.update')->sole();
        $this->assertSame(['name' => 'Till 1'], $update->before);
        $this->assertSame(['name' => 'Till 2'], $update->after);
    }

    public function test_entries_chain_and_the_chain_verifies(): void
    {
        $company = $this->company();
        $company->update(['name' => 'A Holdings']);

        [$first, $second] = AuditEntry::orderBy('seq')->get()->all();
        $this->assertSame(str_repeat('0', 64), $first->prev_hash);
        $this->assertSame($first->hash, $second->prev_hash);
        $this->assertSame(2, $second->seq);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $second->hash);

        $head = DB::table('audit_chain_heads')->where('tenant_id', $this->a->id)->sole();
        $this->assertSame(2, (int) $head->seq);
        $this->assertSame($second->hash, $head->hash);

        $this->assertNull(app(Auditor::class)->verify($this->a->id));
    }

    public function test_context_fields_are_stored_and_round_trip_through_the_hash(): void
    {
        $user = (string) Str::uuid7();
        $onBehalf = (string) Str::uuid7();
        $device = (string) Str::uuid7();
        $location = (string) Str::uuid7();

        app(AuditContext::class)
            ->setUserId(strtoupper($user))
            ->setOnBehalfOfUserId($onBehalf)
            ->setIp('2001:0DB8:0000:0000:0000:0000:0000:0001')
            ->setUserAgent('Till/1.0 (Android; ünïcödé)')
            ->setDeviceId($device)
            ->setLocationId($location)
            ->setDeviceTime('2026-10-07T13:45:10.120000+03:00');

        // Unordered keys, nested lists, unicode, slashes, floats with and
        // without a fraction, big integers and empty containers: jsonb
        // reorders keys and reformats numbers, so verify must still match.
        $entry = app(Auditor::class)->record('core.settings.edit', null,
            ['z' => 1, 'a' => ['y' => 1.5, 'b' => [3, 2, 1]], 'path' => 'a/b', 'empty' => [], 'whole' => 10.0],
            ['name' => 'Société Générale — Kinshasa', 'rate' => 0.1 + 0.2, 'big' => 9007199254740993, 'nested' => ['k' => null, 'b' => true]],
        );

        $stored = AuditEntry::findOrFail($entry->id);
        $this->assertSame(strtolower($user), $stored->user_id);
        $this->assertSame($onBehalf, $stored->on_behalf_of_user_id);
        $this->assertSame('2001:db8::1', $stored->ip);
        $this->assertSame($device, $stored->device_id);
        $this->assertSame($location, $stored->location_id);
        $this->assertSame('Till/1.0 (Android; ünïcödé)', $stored->user_agent);
        $this->assertSame('2026-10-07T10:45:10.120000Z', $stored->device_time->utc()->format('Y-m-d\TH:i:s.u\Z'));
        $this->assertSame('core', $stored->module);
        $this->assertSame($entry->hash, $stored->hash);

        $this->assertNull(app(Auditor::class)->verify($this->a->id));
    }

    public function test_microsecond_timestamps_with_trailing_zeros_verify(): void
    {
        // PostgreSQL trims trailing zeros from fractional seconds on output.
        foreach (['2026-10-07T10:00:00.000000Z', '2026-10-07T10:00:00.500000Z', '2026-10-07T10:00:00.123456Z'] as $at) {
            app(Auditor::class)->record('core.settings.edit', null, null, ['at' => $at], ['occurred_at' => $at, 'device_time' => $at]);
        }

        $this->assertSame(3, AuditEntry::count());
        $this->assertNull(app(Auditor::class)->verify($this->a->id));
    }

    public function test_canonical_json_sorts_keys_recursively_and_keeps_list_order(): void
    {
        $this->assertSame(
            '{"a":{"b":2,"c":[3,1,{"x":1,"y":2}]},"z":"é/ü"}',
            Auditor::canonicalJson(['z' => 'é/ü', 'a' => ['c' => [3, 1, ['y' => 2, 'x' => 1]], 'b' => 2]]),
        );
    }

    public function test_runtime_role_cannot_update_audit_logs(): void
    {
        $this->company();

        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::update("update audit_logs set action = 'tampered'"));
    }

    public function test_runtime_role_cannot_delete_audit_logs(): void
    {
        $this->company();

        try {
            DB::transaction(fn () => DB::delete('delete from audit_logs'));
            $this->fail('Delete was allowed');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }

        $this->assertSame(1, AuditEntry::count());
        $this->assertFalse((bool) DB::selectOne("select has_table_privilege(current_user, 'audit_logs', 'UPDATE') as p")->p);
        $this->assertFalse((bool) DB::selectOne("select has_table_privilege(current_user, 'audit_logs', 'DELETE') as p")->p);
        $this->assertFalse((bool) DB::selectOne("select has_table_privilege(current_user, 'audit_logs', 'TRUNCATE') as p")->p);
    }

    public function test_the_model_refuses_to_update_or_delete_an_entry(): void
    {
        $this->company();
        $entry = AuditEntry::first();

        try {
            $entry->delete();
            $this->fail('Delete was allowed');
        } catch (LogicException $e) {
            $this->assertSame('audit log is append-only', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $entry->update(['action' => 'tampered']);
    }

    public function test_tenant_b_cannot_read_tenant_a_entries(): void
    {
        $this->company();
        $this->assertSame(1, AuditEntry::count());

        $this->context->set($this->b->id);
        $this->assertSame(0, AuditEntry::count());
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('audit_chain_heads')->count());

        // B's chain is independent and starts at 1.
        $entry = app(Auditor::class)->record('core.settings.edit');
        $this->assertSame(1, $entry->seq);
        $this->assertSame(str_repeat('0', 64), $entry->prev_hash);

        // Verifying A from B's context still only sees A (and restores B).
        $this->assertNull(app(Auditor::class)->verify($this->a->id));
        $this->assertSame($this->b->id, $this->context->id());
    }

    public function test_recording_without_a_tenant_throws(): void
    {
        $this->context->set(null);

        $this->expectException(TenantContextMissing::class);
        app(Auditor::class)->record('core.settings.edit');
    }

    public function test_verify_command_reports_an_intact_chain(): void
    {
        $this->company()->update(['name' => 'A Holdings']);

        $this->artisan('audit:verify', ['tenant' => $this->a->id])
            ->expectsOutputToContain('intact')
            ->assertExitCode(0);
    }
}
