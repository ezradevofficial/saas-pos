<?php

namespace Tests\Feature\Core\DataExport;

use App\Core\Audit\AuditEntry;
use App\Core\Audit\Auditor;
use App\Core\DataExport\ExportColumns;
use App\Core\DataExport\Jobs\BuildTenantExport;
use App\Core\DataExport\Models\TenantExport;
use App\Core\DataExport\TenantTables;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\WithoutOwnerConnection;
use Tests\TestCase;
use ZipArchive;

/**
 * EXP-02: the Owner's full tenant export. Built in the background under
 * row-level security (TEN-01), secrets left out, files included, a
 * manifest with SHA-256 hashes, a signed download bound to the requesting
 * Owner (RBAC-10), deleted after 7 days, rate-limited, notified and
 * audited (AUD-01).
 */
class TenantExportTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase, WithoutOwnerConnection;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('media');
        $this->setUpOrganisation();
    }

    /** Request an export as $user (the owner by default) and return it, built (sync queue). */
    private function export(?User $user = null): TenantExport
    {
        $id = $this->postJson('/api/v1/tenant-exports', [], $this->headersFor($user))->assertStatus(202)->json('data.id');

        return $this->inTenant(fn () => TenantExport::query()->findOrFail($id));
    }

    private function zip(TenantExport $export): ZipArchive
    {
        $this->assertSame(TenantExport::READY, $export->status, (string) $export->failure);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('media')->path($export->path)));

        return $zip;
    }

    /** @return array<string, mixed> */
    private function manifest(ZipArchive $zip): array
    {
        return json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private function rows(ZipArchive $zip, string $table): array
    {
        $content = $zip->getFromName("tables/{$table}.jsonl");
        $this->assertNotFalse($content, "tables/{$table}.jsonl is in the export");

        return array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", (string) $content))));
    }

    private function allContent(ZipArchive $zip): string
    {
        $content = '';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content .= $zip->getFromIndex($i)."\n";
        }

        return $content;
    }

    public function test_the_export_has_a_file_per_tenant_table_with_only_this_tenants_rows(): void
    {
        $other = $this->otherTenant();
        $tenantId = $this->owner->tenant_id;

        $export = $this->export();
        $zip = $this->zip($export);
        $manifest = $this->manifest($zip);
        $tables = $this->inTenant(fn () => app(TenantTables::class)->all());

        // Every table with a tenant_id under row-level security, discovered from the schema.
        $this->assertSame(array_column($tables, 'name'), array_column($manifest['tables'], 'name'));
        $this->assertContains('users', array_column($manifest['tables'], 'name'));
        $this->assertContains('audit_logs', array_column($manifest['tables'], 'name'));
        $this->assertContains('tenant_exports', array_column($manifest['tables'], 'name'));
        $this->assertSame(['personal_access_tokens', 'verification_challenges'], $manifest['omitted']['tables']);
        $this->assertSame(['id' => $tenantId, 'name' => $this->inTenant(fn () => DB::table('tenants')->value('name'))], $manifest['tenant']);
        $this->assertSame($export->id, $manifest['export_id']);
        $this->assertSame(DB::table('migrations')->orderByDesc('id')->value('migration'), $manifest['schema_version']);
        $this->assertNotEmpty($manifest['generated_at']);

        foreach ($manifest['tables'] as $table) {
            $rows = $this->rows($zip, $table['name']);
            $this->assertCount($table['rows'], $rows, "{$table['name']}: the manifest counts its rows");

            foreach ($rows as $row) {
                $this->assertSame($table['columns'], array_keys($row), "{$table['name']}: columns in table order");
                $this->assertSame($tenantId, $row[$table['name'] === 'tenants' ? 'id' : 'tenant_id'], "{$table['name']}: only this tenant's rows");
            }
        }

        $this->assertCount(1, $this->rows($zip, 'tenants'));
        $this->assertSame(['Acme', 'Outlet A'], [
            collect($this->rows($zip, 'companies'))->pluck('name')->sort()->values()->all()[0],
            collect($this->rows($zip, 'locations'))->pluck('name')->sort()->values()->all()[0],
        ]);
        $this->assertSame((int) $export->table_count, count($manifest['tables']));
        $this->assertSame((int) $export->row_count, array_sum(array_column($manifest['tables'], 'rows')));

        // Nothing of the other tenant, anywhere in the zip.
        $content = $this->allContent($zip);
        foreach ([$other['user']->tenant_id, $other['user']->id, $other['user']->email, $other['company']->id, $other['branch']->id, $other['location']->id, 'Other outlet'] as $value) {
            $this->assertStringNotContainsString((string) $value, $content);
        }
    }

    public function test_secrets_are_left_out(): void
    {
        $this->headersFor(); // signed in before the password changes
        $this->inTenant(function () {
            $this->owner->forceFill(['password' => Hash::make('Correct-Horse-2026'), 'two_factor_secret' => 'JBSWY3DPEHPK3PXPSECRET'])->save();
        });
        $hash = $this->inTenant(fn () => DB::table('users')->where('id', $this->owner->id)->value('password'));

        $zip = $this->zip($this->export());
        $manifest = $this->manifest($zip);
        $content = $this->allContent($zip);

        $this->assertStringNotContainsString($hash, $content);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXPSECRET', $content);
        $users = collect($manifest['tables'])->firstWhere('name', 'users');
        $this->assertNotContains('password', $users['columns']);
        $this->assertNotContains('two_factor_secret', $users['columns']);
        $this->assertContains('password', $users['omitted_columns']);
        $this->assertContains('email', $users['columns']);
        $this->assertSame(array_keys(ExportColumns::DENIED), $manifest['omitted']['columns']);

        foreach ($manifest['tables'] as $table) {
            foreach (ExportColumns::deniedIn($table['name']) as $column) {
                $this->assertNotContains($column, $table['columns'], "{$table['name']}.{$column} is never exported");

                foreach ($this->rows($zip, $table['name']) as $row) {
                    $this->assertArrayNotHasKey($column, $row);
                }
            }
        }
    }

    public function test_the_manifest_hashes_match_every_file(): void
    {
        Storage::disk('media')->put("tenants/{$this->owner->tenant_id}/items/item-1/photo.jpg", 'jpeg bytes');

        $export = $this->export();
        $zip = $this->zip($export);
        $manifest = $this->manifest($zip);
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertEqualsCanonicalizing(['manifest.json', ...array_column($manifest['files'], 'path')], $names);

        foreach ($manifest['files'] as $file) {
            $content = (string) $zip->getFromName($file['path']);
            $this->assertSame(hash('sha256', $content), $file['sha256'], $file['path']);
            $this->assertSame(strlen($content), $file['size'], $file['path']);
        }

        // The zip itself: its SHA-256 and size are kept with the export.
        $bytes = Storage::disk('media')->get($export->path);
        $this->assertSame(hash('sha256', $bytes), $export->sha256);
        $this->assertSame(strlen($bytes), $export->size);
    }

    public function test_the_tenants_media_files_are_included_and_nothing_else(): void
    {
        $other = $this->otherTenant();
        $tenantId = $this->owner->tenant_id;
        $media = Storage::disk('media');
        $media->put("tenants/{$tenantId}/items/item-1/photo.jpg", 'item image');
        $media->put("tenants/{$tenantId}/branding/logo.png", 'brand logo');
        $media->put("tenants/{$tenantId}/custom-fields/item/spec.pdf", 'custom field file');
        $media->put("tenants/{$tenantId}/approvals/req-1/quote.pdf", 'approval attachment');
        $media->put("tenants/{$tenantId}/exports/older.zip", 'an earlier export');
        $media->put("tenants/{$other['user']->tenant_id}/items/item-9/photo.jpg", 'other tenant image');

        $export = $this->export();
        $zip = $this->zip($export);

        $this->assertSame('item image', $zip->getFromName('files/items/item-1/photo.jpg'));
        $this->assertSame('brand logo', $zip->getFromName('files/branding/logo.png'));
        $this->assertSame('custom field file', $zip->getFromName('files/custom-fields/item/spec.pdf'));
        $this->assertSame('approval attachment', $zip->getFromName('files/approvals/req-1/quote.pdf'));
        $this->assertFalse($zip->getFromName('files/exports/older.zip'));
        $this->assertStringNotContainsString('other tenant image', $this->allContent($zip));
        $this->assertSame(4, $export->file_count);
    }

    public function test_only_an_active_owner_may_export(): void
    {
        $admin = $this->userWith('admin', Scope::tenant());
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $holder = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Exporter', ['core.tenant.export']), Scope::tenant());

            return $user;
        });

        // The permission is the Owner's alone among the templates.
        $this->assertFalse($this->inTenant(fn () => $this->roles->get('admin')->hasPermissionTo('core.tenant.export')));
        $this->assertTrue($this->inTenant(fn () => $this->roles->get('owner')->hasPermissionTo('core.tenant.export')));

        foreach ([$admin, $manager, $holder] as $user) {
            $this->getJson('/api/v1/tenant-exports', $this->headersFor($user))->assertForbidden();
            $this->postJson('/api/v1/tenant-exports', [], $this->headersFor($user))->assertForbidden();
        }

        $this->assertSame(0, $this->inTenant(fn () => TenantExport::count()));
        $this->getJson('/api/v1/tenant-exports', $this->headersFor())->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/tenant-exports')->assertUnauthorized();
    }

    public function test_the_download_url_is_signed_and_bound_to_the_requesting_owner(): void
    {
        $second = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->roles->get('owner'), Scope::tenant());

            return $user;
        });
        $export = $this->export();

        $url = $this->getJson('/api/v1/tenant-exports', $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.status', 'ready')->json('data.0.download_url');
        $this->assertNotNull($url);
        $this->assertStringContainsString("user={$this->owner->id}", $url);

        // Another Owner sees the export but gets no link to it.
        $this->getJson('/api/v1/tenant-exports', $this->headersFor($second))->assertOk()
            ->assertJsonPath('data.0.id', $export->id)->assertJsonPath('data.0.download_url', null);

        // Changing the user (or anything else) breaks the signature.
        $this->get(str_replace("user={$this->owner->id}", "user={$second->id}", $url))->assertForbidden();

        // The requester downloads it, without a bearer token: the signature is the credential.
        $response = $this->get($url)->assertOk();
        $this->assertSame(Storage::disk('media')->get($export->path), $response->streamedContent());
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $download = $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.tenant_export.download')->sole());
        $this->assertSame([$this->owner->id, $export->id], [$download->user_id, $download->auditable_id]);
        $this->assertNotNull($this->inTenant(fn () => $export->fresh()->downloaded_at));

        // The link expires after 24 hours.
        $this->travel(25)->hours();
        $this->get($url)->assertForbidden();
        $this->travelBack();

        // A requester who is no longer an Owner cannot use it.
        $this->inTenant(fn () => DB::table('role_assignments')->where('user_id', $this->owner->id)->delete());
        $this->get($url)->assertNotFound();
    }

    public function test_expired_exports_are_deleted_by_the_scheduled_clean_up(): void
    {
        $export = $this->export();
        $stale = $this->inTenant(fn () => TenantExport::create(['requested_by' => $this->owner->id, 'status' => TenantExport::QUEUED]));
        $this->inTenant(fn () => DB::table('tenant_exports')->where('id', $stale->id)->update(['created_at' => now()->subHours(7)]));
        Storage::disk('media')->assertExists($export->path);
        $this->assertTrue($export->expires_at->equalTo($export->completed_at->addDays(7)));
        $this->withoutOwnerConnection();

        // Six days on: kept (the stale build is failed).
        $this->artisan('exports:expire', ['--at' => CarbonImmutable::now()->addDays(6)->toIso8601String()])->assertSuccessful();
        Storage::disk('media')->assertExists($export->path);
        $this->assertSame([TenantExport::READY, TenantExport::FAILED, 'stale'], $this->inTenant(fn () => [$export->fresh()->status, $stale->fresh()->status, $stale->fresh()->failure]));

        // Seven days on: the file is deleted, the row kept as expired (TEN-06).
        $this->artisan('exports:expire', ['--at' => CarbonImmutable::now()->addDays(7)->addMinute()->toIso8601String()])->assertSuccessful();
        Storage::disk('media')->assertMissing($export->path);
        $fresh = $this->inTenant(fn () => $export->fresh());
        $this->assertSame([TenantExport::EXPIRED, null], [$fresh->status, $fresh->path]);
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::where('action', 'core.tenant_export.expire')->count()));

        $this->getJson('/api/v1/tenant-exports', $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.id', $export->id)->assertJsonPath('data.0.status', 'expired')->assertJsonPath('data.0.download_url', null)
            ->assertJsonPath('data.1.status', 'failed');
    }

    public function test_one_export_at_a_time_and_three_a_day(): void
    {
        Queue::fake();
        $this->postJson('/api/v1/tenant-exports', [], $this->headersFor())->assertStatus(202)->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(BuildTenantExport::class, fn (BuildTenantExport $job) => $job->tenantId === $this->owner->tenant_id && $job->queue === 'exports');
        $this->postJson('/api/v1/tenant-exports', [], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'export_in_progress');

        // Finished ones count towards the daily limit.
        $this->inTenant(fn () => TenantExport::query()->update(['status' => TenantExport::FAILED]));
        $this->postJson('/api/v1/tenant-exports', [], $this->headersFor())->assertStatus(202);
        $this->inTenant(fn () => TenantExport::query()->update(['status' => TenantExport::FAILED]));
        $this->postJson('/api/v1/tenant-exports', [], $this->headersFor())->assertStatus(202);
        $this->inTenant(fn () => TenantExport::query()->update(['status' => TenantExport::FAILED]));
        $this->postJson('/api/v1/tenant-exports', [], $this->headersFor())->assertStatus(429)->assertJsonPath('code', 'export_limit');
        $this->assertSame(3, $this->inTenant(fn () => TenantExport::count()));

        // A day later, one more.
        $this->inTenant(fn () => DB::table('tenant_exports')->update(['created_at' => now()->subHours(25)]));
        $this->postJson('/api/v1/tenant-exports', [], $this->headersFor())->assertStatus(202);
    }

    public function test_the_owner_is_notified_and_each_step_is_audited(): void
    {
        $export = $this->export();

        $deliveries = $this->inTenant(fn () => NotificationDelivery::query()->where('event_type', 'core.tenant_export.ready')->get());
        $this->assertEqualsCanonicalizing(['in_app', 'email'], $deliveries->pluck('channel')->all());
        $this->assertTrue($deliveries->every(fn ($d) => $d->user_id === $this->owner->id && $d->link === '/settings/data-export'));
        $this->assertStringContainsString('Your data export is ready', (string) $deliveries->first()->subject);

        $entries = $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $export->id)->orderBy('seq')->get());
        $this->assertSame(['core.tenant_export.request', 'core.tenant_export.ready'], $entries->pluck('action')->all());
        $this->assertSame($this->owner->id, $entries[0]->user_id);
        $this->assertSame($export->sha256, $entries[1]->after['sha256']);
        $this->assertNull($this->inTenant(fn () => app(Auditor::class)->verify($this->owner->tenant_id)));
    }
}
