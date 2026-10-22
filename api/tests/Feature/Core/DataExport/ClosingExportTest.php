<?php

namespace Tests\Feature\Core\DataExport;

use App\Core\Audit\AuditEntry;
use App\Core\DataExport\ClosingExportReady;
use App\Core\DataExport\Models\TenantExport;
use App\Core\DataExport\TenantExports;
use App\Core\Identity\Models\User;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformDatabase;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * NFR-08, EXP-02 (Phase 8 review decision on H2, built as the default):
 * when a tenant closes, its full export is built once and each active
 * Owner is emailed a signed link that works without signing in (a closed
 * tenant refuses sign-in). Tampered links answer 403, expired ones 410;
 * downloads are audited in the tenant's chain; staff can send the links
 * again (audited); sandboxes get nothing.
 */
class ClosingExportTest extends TestCase
{
    use BuildsOrganisation, CreatesPlatformStaff, RefreshTenantDatabase, ResetsPlatformTables;

    private User $secondOwner;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('media');
        $this->setUpOrganisation();
        $this->secondOwner = $this->userWith('owner', Scope::tenant());
    }

    private function close(string $tenantId): void
    {
        $this->asTenant($tenantId, fn () => Tenant::query()->whereKey($tenantId)->firstOrFail()->forceFill(['status' => 'closed'])->save());
    }

    /** The link emailed to $owner (the last one). */
    private function linkFor(User $owner): string
    {
        $url = null;
        Notification::assertSentTo($owner, ClosingExportReady::class, function (ClosingExportReady $mail) use (&$url) {
            $url = $mail->url;

            return true;
        });

        return (string) $url;
    }

    private function closingExports(?string $tenantId = null): array
    {
        return $this->asTenant($tenantId ?? $this->owner->tenant_id, fn () => TenantExport::query()->where('purpose', TenantExport::PURPOSE_CLOSING)->get()->all());
    }

    public function test_closing_builds_one_export_and_each_active_owner_downloads_it_without_signing_in(): void
    {
        $this->close($this->owner->tenant_id);

        $exports = $this->closingExports();
        $this->assertCount(1, $exports);
        $this->assertSame(TenantExport::READY, $exports[0]->status);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $exports[0]->expires_at->getTimestamp(), 5);
        Notification::assertSentTo([$this->owner, $this->secondOwner], ClosingExportReady::class);

        // No bearer token, no session: the signed link is the credential.
        $download = $this->get($this->linkFor($this->owner))->assertOk();
        $this->assertSame('application/zip', $download->headers->get('Content-Type'));
        $this->get($this->linkFor($this->secondOwner))->assertOk();

        $this->inTenant(function () {
            $this->assertSame(2, AuditEntry::where('action', 'core.tenant_export.download')->count());
            $this->assertSame(1, AuditEntry::where('action', 'core.tenant_export.closing_request')->count());
            $sent = AuditEntry::where('action', 'core.tenant_export.closing_links')->sole();
            $this->assertSame(2, $sent->after['recipients']);
            $this->assertStringNotContainsString('signature', json_encode($sent->after));
        });

        // One export per closing: the listener running twice for this closing builds nothing more.
        $this->asTenant($this->owner->tenant_id, fn () => app(TenantExports::class)->requestOnClosing());
        $this->assertCount(1, $this->closingExports());
    }

    /** Reopened and closed again, a tenant gets a fresh export; one closing never yields two. */
    public function test_each_closing_gets_its_own_export_and_only_one(): void
    {
        $this->close($this->owner->tenant_id);
        $this->travel(1)->minutes();
        $this->asTenant($this->owner->tenant_id, fn () => Tenant::query()->whereKey($this->owner->tenant_id)->firstOrFail()->forceFill(['status' => 'active'])->save());
        $this->travel(1)->minutes();
        $this->close($this->owner->tenant_id);

        $this->asTenant($this->owner->tenant_id, fn () => app(TenantExports::class)->requestOnClosing());
        $exports = $this->closingExports();
        $this->assertCount(2, $exports);
        $this->assertSame([TenantExport::READY, TenantExport::READY], array_map(fn (TenantExport $export) => $export->status, $exports));
        Notification::assertSentToTimes($this->owner, ClosingExportReady::class, 2);

        // The latest closing's link is the one that downloads.
        $this->get($this->linkFor($this->owner))->assertOk();
    }

    public function test_tampered_expired_and_other_tenants_links_are_refused(): void
    {
        $other = $this->otherTenant();
        $this->close($this->owner->tenant_id);
        $this->close($other['user']->tenant_id);
        $mine = $this->linkFor($this->owner);
        $theirs = $this->linkFor($other['user']);
        $myExport = $this->closingExports()[0]->id;
        $theirExport = $this->closingExports($other['user']->tenant_id)[0]->id;

        // Tampered: another user, another tenant, a changed signature.
        $this->getJson(str_replace($this->owner->id, $this->secondOwner->id, $mine))->assertForbidden()->assertJsonPath('code', 'export_link_invalid');
        $this->getJson(str_replace($this->owner->tenant_id, $other['user']->tenant_id, $mine))->assertForbidden();
        $this->getJson(preg_replace('/signature=[0-9a-f]{4}/', 'signature=0000', $mine))->assertForbidden();
        // Another tenant's link, pointed at this tenant's export.
        $this->getJson(str_replace($theirExport, $myExport, $theirs))->assertForbidden();
        // A link of a tenant that is no longer closed is not served.
        $this->asTenant($other['user']->tenant_id, fn () => Tenant::query()->whereKey($other['user']->tenant_id)->update(['status' => 'active']));
        $this->getJson($theirs)->assertNotFound();

        // Expired: the link answers 410 (a short page in the browser's language), and the file goes.
        $this->getJson($mine)->assertOk();
        $this->travel(31)->days();
        $this->getJson($mine)->assertStatus(410)->assertJsonPath('code', 'export_link_expired');
        $page = $this->get($mine, ['Accept' => 'text/html', 'Accept-Language' => 'fr'])->assertStatus(410);
        $this->assertStringContainsString('Ce lien a expiré', $page->getContent());

        $this->artisan('exports:expire')->assertSuccessful();
        $this->inTenant(fn () => $this->assertSame(TenantExport::EXPIRED, TenantExport::query()->findOrFail($myExport)->status));
    }

    public function test_downloads_are_rate_limited(): void
    {
        config(['data_export.downloads_per_hour' => 2]);
        $this->close($this->owner->tenant_id);
        $link = $this->linkFor($this->owner);

        $this->getJson($link)->assertOk();
        $this->getJson($link)->assertOk();
        $this->getJson($link)->assertStatus(429);
    }

    public function test_a_sandbox_gets_no_closing_export(): void
    {
        $sandbox = $this->createUser([], ['is_sandbox' => true]);
        $this->close($sandbox->tenant_id);

        $this->assertSame([], $this->closingExports($sandbox->tenant_id));
        Notification::assertNotSentTo($sandbox, ClosingExportReady::class);
    }

    public function test_staff_can_send_the_links_again_and_it_is_audited(): void
    {
        $support = $this->staff(PlatformUser::ROLE_SUPPORT);
        $this->postJson("/api/v1/platform/tenants/{$this->owner->tenant_id}/closing-export/resend", ['reason' => 'The Owner lost the first email.'], $this->staffBearer($support))
            ->assertStatus(409)->assertJsonPath('code', 'closing_export_unavailable');

        $this->close($this->owner->tenant_id);
        $this->postJson("/api/v1/platform/tenants/{$this->owner->tenant_id}/closing-export/resend", ['reason' => 'short'], $this->staffBearer($support))->assertUnprocessable();
        $this->postJson("/api/v1/platform/tenants/{$this->owner->tenant_id}/closing-export/resend", ['reason' => 'The Owner lost the first email.'], $this->staffBearer($support))
            ->assertOk()->assertJsonPath('data.recipients', 2);
        Notification::assertSentToTimes($this->owner, ClosingExportReady::class, 2);

        $this->inTenant(function () use ($support) {
            $entry = AuditEntry::where('action', 'core.tenant_export.closing_resend')->sole();
            $this->assertSame('The Owner lost the first email.', $entry->after['reason']);
            $this->assertSame($support->id, $entry->after['metadata']['platform_user_id'] ?? null);
        });
        $this->assertSame(1, PlatformDatabase::connection()->table('platform_audit_logs')->where('action', 'platform.tenant.closing_export_resend')->count());

        // Sales staff may not.
        $this->postJson("/api/v1/platform/tenants/{$this->owner->tenant_id}/closing-export/resend", ['reason' => 'The Owner lost the first email.'], $this->staffBearer($this->staff(PlatformUser::ROLE_SALES)))->assertForbidden();
    }
}
