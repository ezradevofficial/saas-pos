<?php

namespace Tests\Feature\Core\DataExport;

use App\Core\Audit\AuditEntry;
use App\Core\Audit\Auditor;
use App\Core\DataExport\ExportColumns;
use App\Core\DataExport\Jobs\BuildTenantExport;
use App\Core\DataExport\Models\TenantExport;
use App\Core\DataExport\TenantExportBuilder;
use App\Core\DataExport\TenantTables;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
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
        $id = $this->postJson('/api/v1/tenant-exports', ['password' => $this->password], $this->headersFor($user))->assertStatus(202)->json('data.id');

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
            $this->owner->forceFill(['password' => Hash::make($this->password), 'two_factor_secret' => 'JBSWY3DPEHPK3PXPSECRET'])->save();
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
            $this->postJson('/api/v1/tenant-exports', ['password' => $this->password], $this->headersFor($user))->assertForbidden();
        }

        $this->assertSame(0, $this->inTenant(fn () => TenantExport::count()));
        $this->getJson('/api/v1/tenant-exports', $this->headersFor())->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/tenant-exports')->assertUnauthorized();
    }

    /** A download link of $export for $user (authenticated POST .../link). */
    private function link(TenantExport $export, ?User $user = null): string
    {
        return $this->postJson("/api/v1/tenant-exports/{$export->id}/link", [], $this->headersFor($user))->assertOk()->json('data.url');
    }

    public function test_requesting_an_export_confirms_the_owners_password(): void
    {
        $this->postJson('/api/v1/tenant-exports', [], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/tenant-exports', ['password' => 'not-the-password'], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_password');
        $this->assertSame(0, $this->inTenant(fn () => TenantExport::count()));

        $this->postJson('/api/v1/tenant-exports', ['password' => $this->password], $this->headersFor())->assertStatus(202);
    }

    public function test_the_list_never_carries_a_download_url(): void
    {
        $export = $this->export();

        $listed = $this->getJson('/api/v1/tenant-exports', $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.id', $export->id)->assertJsonPath('data.0.can_download', true);
        $this->assertStringNotContainsString('signature=', (string) $listed->getContent());
        $this->assertArrayNotHasKey('download_url', $listed->json('data.0'));
    }

    public function test_the_download_link_is_minted_on_request_and_bound_to_the_requesting_owner(): void
    {
        $second = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->roles->get('owner'), Scope::tenant());

            return $user;
        });
        $export = $this->export();

        // Another Owner sees the export but can neither get a link nor use the requester's.
        $this->getJson('/api/v1/tenant-exports', $this->headersFor($second))->assertOk()
            ->assertJsonPath('data.0.id', $export->id)->assertJsonPath('data.0.can_download', false);
        $this->postJson("/api/v1/tenant-exports/{$export->id}/link", [], $this->headersFor($second))->assertNotFound();
        $this->postJson("/api/v1/tenant-exports/{$export->id}/link")->assertUnauthorized();

        $url = $this->link($export);
        $this->assertStringContainsString("user={$this->owner->id}", $url);

        // Changing the user (or anything else) breaks the signature.
        $this->get(str_replace("user={$this->owner->id}", "user={$second->id}", $url))->assertForbidden();

        // The requester downloads it, without a bearer token: the signature is the credential.
        $response = $this->get($url)->assertOk();
        $this->assertSame(Storage::disk('media')->get($export->path), $response->streamedContent());
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $download = $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.tenant_export.download')->sole());
        $this->assertSame([$this->owner->id, $export->id], [$download->user_id, $download->auditable_id]);
        $this->assertNotNull($this->inTenant(fn () => $export->fresh()->downloaded_at));

        // The link lasts five minutes.
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
        $this->travelBack();

        // A requester who is no longer an Owner cannot use a fresh one.
        $url = $this->link($export);
        $this->inTenant(fn () => DB::table('role_assignments')->where('user_id', $this->owner->id)->delete());
        $this->get($url)->assertNotFound();
    }

    public function test_downloads_of_one_export_are_limited_per_hour(): void
    {
        $export = $this->export();
        $url = $this->link($export);

        for ($i = 0; $i < 5; $i++) {
            $this->get($url)->assertOk();
        }

        $this->get($url)->assertStatus(429);
    }

    public function test_expired_exports_are_deleted_by_the_scheduled_clean_up(): void
    {
        $export = $this->export();
        $queued = $this->inTenant(fn () => TenantExport::create(['requested_by' => $this->owner->id, 'status' => TenantExport::QUEUED]));
        Storage::disk('media')->assertExists($export->path);
        $this->assertTrue($export->expires_at->equalTo($export->completed_at->addDays(7)));
        $this->withoutOwnerConnection();

        // Six days on: kept; the queued build (no worker for six days) is failed.
        $this->artisan('exports:expire', ['--at' => CarbonImmutable::now()->addDays(6)->toIso8601String()])->assertSuccessful();
        Storage::disk('media')->assertExists($export->path);
        $this->assertSame([TenantExport::READY, TenantExport::FAILED, 'stale'], $this->inTenant(fn () => [$export->fresh()->status, $queued->fresh()->status, $queued->fresh()->failure]));

        // Seven days on: the file is deleted, the row kept as expired (TEN-06).
        $this->artisan('exports:expire', ['--at' => CarbonImmutable::now()->addDays(7)->addMinute()->toIso8601String()])->assertSuccessful();
        Storage::disk('media')->assertMissing($export->path);
        $fresh = $this->inTenant(fn () => $export->fresh());
        $this->assertSame([TenantExport::EXPIRED, null], [$fresh->status, $fresh->path]);
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::where('action', 'core.tenant_export.expire')->count()));

        $listed = collect($this->getJson('/api/v1/tenant-exports', $this->headersFor())->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['expired', false], [$listed[$export->id]['status'], $listed[$export->id]['can_download']]);
        $this->assertSame('failed', $listed[$queued->id]['status']);
    }

    public function test_running_builds_go_stale_from_their_start_and_queued_ones_after_a_day(): void
    {
        $this->withoutOwnerConnection();
        $at = CarbonImmutable::now();
        $make = fn (string $status, array $times) => $this->inTenant(function () use ($status, $times) {
            $export = TenantExport::create(['requested_by' => $this->owner->id, 'status' => $status]);
            DB::table('tenant_exports')->where('id', $export->id)->update($times);

            return $export;
        });

        // Queued long ago but started 30 minutes ago: still building.
        $running = $make(TenantExport::RUNNING, ['created_at' => $at->subHours(20), 'started_at' => $at->subMinutes(30), 'path' => "tenants/{$this->owner->tenant_id}/exports/x.zip", 'disk' => 'media']);
        Storage::disk('media')->put("tenants/{$this->owner->tenant_id}/exports/x.zip.part", 'partial upload');
        $this->artisan('exports:expire', ['--at' => $at->toIso8601String()])->assertSuccessful();
        $this->assertSame(TenantExport::RUNNING, $this->inTenant(fn () => $running->fresh()->status));

        // Started more than the timeout plus an hour ago: a dead worker. Its partial upload is removed.
        $this->artisan('exports:expire', ['--at' => $at->addHours(2)->addMinute()->toIso8601String()])->assertSuccessful();
        $this->assertSame([TenantExport::FAILED, 'stale', null], $this->inTenant(fn () => [$running->fresh()->status, $running->fresh()->failure, $running->fresh()->path]));
        Storage::disk('media')->assertMissing("tenants/{$this->owner->tenant_id}/exports/x.zip.part");

        // Queued for 23 hours: waiting for a worker; for 25: failed.
        $queued = $make(TenantExport::QUEUED, ['created_at' => $at->subHours(23)]);
        $this->artisan('exports:expire', ['--at' => $at->toIso8601String()])->assertSuccessful();
        $this->assertSame(TenantExport::QUEUED, $this->inTenant(fn () => $queued->fresh()->status));
        $this->artisan('exports:expire', ['--at' => $at->addHours(2)->toIso8601String()])->assertSuccessful();
        $this->assertSame(TenantExport::FAILED, $this->inTenant(fn () => $queued->fresh()->status));
    }

    public function test_a_file_that_cannot_be_deleted_keeps_its_export_until_the_next_run(): void
    {
        $export = $this->export();
        $real = Storage::disk('media');
        $failing = Mockery::mock(Filesystem::class);
        $failing->shouldReceive('exists')->andReturn(true);
        $failing->shouldReceive('delete')->andReturn(false);
        Storage::set('media', $failing);

        $this->artisan('exports:expire', ['--at' => CarbonImmutable::now()->addDays(8)->toIso8601String()])->assertSuccessful();
        $fresh = $this->inTenant(fn () => $export->fresh());
        $this->assertSame([TenantExport::READY, $export->path], [$fresh->status, $fresh->path]);
        $this->assertFalse($fresh->isDownloadable(CarbonImmutable::now()->addDays(8)));
        $this->assertSame(0, $this->inTenant(fn () => AuditEntry::where('action', 'core.tenant_export.expire')->count()));

        // The store answers again: the next run deletes it.
        Storage::set('media', $real);
        $this->artisan('exports:expire', ['--at' => CarbonImmutable::now()->addDays(8)->toIso8601String()])->assertSuccessful();
        $this->assertSame(TenantExport::EXPIRED, $this->inTenant(fn () => $export->fresh()->status));
        $real->assertMissing($export->path);
    }

    public function test_an_export_over_the_size_cap_fails_clearly_and_leaves_nothing_behind(): void
    {
        config(['data_export.max_bytes' => 10_000]);
        $id = $this->postJson('/api/v1/tenant-exports', ['password' => $this->password], $this->headersFor())->assertStatus(202)->json('data.id');
        $export = $this->inTenant(fn () => TenantExport::query()->findOrFail($id));

        $this->assertSame([TenantExport::FAILED, 'too_large', null], [$export->status, $export->failure, $export->path]);
        $this->assertSame([], Storage::disk('media')->allFiles("tenants/{$this->owner->tenant_id}/exports"));
        $this->assertSame([], File::directories(storage_path(TenantExportBuilder::TEMP_ROOT)));
    }

    public function test_left_over_work_directories_are_swept(): void
    {
        $old = storage_path(TenantExportBuilder::TEMP_ROOT.'/left-by-a-killed-worker');
        $new = storage_path(TenantExportBuilder::TEMP_ROOT.'/still-building');
        File::ensureDirectoryExists($old);
        File::ensureDirectoryExists($new);
        File::put("{$old}/users.jsonl", '{"email":"someone@example.com"}');
        touch($old, time() - 3 * 3600);

        $this->withoutOwnerConnection();
        $this->artisan('exports:expire')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($new);
        File::deleteDirectory($new);
    }

    public function test_tables_are_read_through_one_cursor_each_never_by_keyset_pages(): void
    {
        config(['data_export.chunk' => 2]);
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $export = $this->export();
        $this->assertSame(TenantExport::READY, $export->status, (string) $export->failure);
        $declares = array_values(array_filter($statements, fn ($sql) => str_starts_with($sql, 'declare tenant_export_rows')));

        $this->assertCount($export->table_count, $declares);
        $this->assertNotEmpty(array_filter($statements, fn ($sql) => str_starts_with($sql, 'fetch forward 2 from tenant_export_rows')));
        $this->assertSame([], array_values(array_filter($statements, fn ($sql) => str_contains($sql, ') > ('))), 'no keyset paging');
    }

    public function test_webhook_urls_and_encrypted_contact_details_are_left_out(): void
    {
        $this->inTenant(function () {
            DB::table('automation_rules')->insert([
                'id' => (string) Str::uuid7(), 'name' => 'Ping', 'document_type' => 'test.doc', 'trigger_type' => 'record_created', 'trigger' => '{}',
                'actions' => json_encode([
                    ['id' => 'a1', 'type' => 'webhook', 'url' => 'https://hooks.example.com/in/abc?token=hook-token-xyz#frag'],
                    ['id' => 'a2', 'type' => 'notify', 'message' => 'Hello'],
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $zip = $this->zip($this->export());
        $content = $this->allContent($zip);
        $this->assertStringNotContainsString('hook-token-xyz', $content);
        $this->assertStringNotContainsString('#frag', $content);

        $rule = collect($this->rows($zip, 'automation_rules'))->firstWhere('name', 'Ping');
        $this->assertSame([
            ['id' => 'a1', 'type' => 'webhook', 'has_url' => true, 'url_display' => 'https://hooks.example.com/in/abc'],
            ['id' => 'a2', 'type' => 'notify', 'message' => 'Hello'],
        ], $rule['actions']);

        $manifest = $this->manifest($zip);
        $this->assertNotContains('url', collect($manifest['tables'])->firstWhere('name', 'automation_webhook_deliveries')['columns']);
        $this->assertNotContains('phone', collect($manifest['tables'])->firstWhere('name', 'payment_intents')['columns']);
    }

    public function test_one_export_at_a_time_and_three_a_day(): void
    {
        Queue::fake();
        $this->postJson('/api/v1/tenant-exports', ['password' => $this->password], $this->headersFor())->assertStatus(202)->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(BuildTenantExport::class, fn (BuildTenantExport $job) => $job->tenantId === $this->owner->tenant_id && $job->queue === 'exports');
        $this->postJson('/api/v1/tenant-exports', ['password' => $this->password], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'export_in_progress');

        // Finished ones count towards the daily limit.
        $this->inTenant(fn () => TenantExport::query()->update(['status' => TenantExport::FAILED]));
        $this->postJson('/api/v1/tenant-exports', ['password' => $this->password], $this->headersFor())->assertStatus(202);
        $this->inTenant(fn () => TenantExport::query()->update(['status' => TenantExport::FAILED]));
        $this->postJson('/api/v1/tenant-exports', ['password' => $this->password], $this->headersFor())->assertStatus(202);
        $this->inTenant(fn () => TenantExport::query()->update(['status' => TenantExport::FAILED]));
        $this->postJson('/api/v1/tenant-exports', ['password' => $this->password], $this->headersFor())->assertStatus(429)->assertJsonPath('code', 'export_limit');
        $this->assertSame(3, $this->inTenant(fn () => TenantExport::count()));

        // A day later, one more.
        $this->inTenant(fn () => DB::table('tenant_exports')->update(['created_at' => now()->subHours(25)]));
        $this->postJson('/api/v1/tenant-exports', ['password' => $this->password], $this->headersFor())->assertStatus(202);
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
