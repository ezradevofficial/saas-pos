<?php

namespace App\Core\Marketplace;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Marketplace\Jobs\AppStatusNotice;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\Models\MarketplaceAppVersion;
use App\Core\Marketplace\OAuth\OAuthClient;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuditor;
use App\Core\Platform\PlatformDatabase;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ADM-05 (docs/adr/024-marketplace.md): platform staff review apps.
 *
 * - Approve a submitted version: it becomes the listed one, and the app's
 *   OAuth client takes its redirect URIs. Installs that consented to fewer
 *   scopes or events keep them until their tenant consents again.
 * - Reject it with a reason; the developer makes a new version.
 * - Suspend an app (with a reason): it leaves the listing, its tokens are
 *   refused at once and its webhooks stop (the install's principal is no
 *   longer active); reinstating restores both.
 *
 * Staff work on the platform connection, which may change only review
 * fields (column grants and policies). Every decision is audited in the
 * platform chain, in the developer tenant's chain, and, for a suspension
 * or reinstatement, in the chain of every tenant that installed the app.
 */
class AppReview
{
    public function __construct(
        private readonly PlatformAuditor $platformAudit,
        private readonly Auditor $auditor,
        private readonly TenantContext $tenants,
    ) {}

    public function approve(PlatformUser $staff, MarketplaceAppVersion $version, ?string $note): MarketplaceAppVersion
    {
        $this->assertSubmitted($version);

        PlatformDatabase::transaction(function () use ($staff, $version, $note) {
            $app = MarketplaceApp::on(PlatformDatabase::CONNECTION)->lock('for no key update')->findOrFail($version->app_id);
            $version->forceFill(['status' => MarketplaceAppVersion::APPROVED, 'reviewed_at' => CarbonImmutable::now(), 'reviewed_by' => $staff->id, 'review_note' => $note])->save();
            $app->forceFill(['listed_version_id' => $version->id])->save();

            // The approved redirect URIs; a first-party connector's are its configuration (marketplace:connector).
            if (AppEndpoints::connectorKey($app) === null) {
                $client = OAuthClient::on(PlatformDatabase::CONNECTION)->findOrFail($app->client_id);
                $client->forceFill(['redirect_uris' => $version->redirectList()])->save();
            }
        });

        $this->audit($staff, 'approve', $version, ['version' => $version->version, 'scopes' => $version->scopeList(), 'webhook_events' => $version->eventList(), 'note' => $note]);

        return $version;
    }

    public function reject(PlatformUser $staff, MarketplaceAppVersion $version, string $reason): MarketplaceAppVersion
    {
        $this->assertSubmitted($version);
        $version->forceFill(['status' => MarketplaceAppVersion::REJECTED, 'reviewed_at' => CarbonImmutable::now(), 'reviewed_by' => $staff->id, 'review_note' => $reason])->save();
        $this->audit($staff, 'reject', $version, ['version' => $version->version, 'reason' => $reason]);

        return $version;
    }

    public function suspend(PlatformUser $staff, MarketplaceApp $app, string $reason): MarketplaceApp
    {
        if ($app->status === MarketplaceApp::SUSPENDED) {
            throw new ApiException(409, 'app_suspended', __('marketplace.errors.app_suspended'));
        }

        $before = $app->status;
        $app->forceFill(['status' => MarketplaceApp::SUSPENDED, 'suspended_reason' => $reason, 'suspended_at' => CarbonImmutable::now(), 'suspended_by' => $staff->id])->save();
        $this->audit($staff, 'suspend', $app, ['status_before' => $before, 'reason' => $reason]);
        $this->tellInstalls($app, true, $reason);

        return $app;
    }

    public function reinstate(PlatformUser $staff, MarketplaceApp $app, string $reason): MarketplaceApp
    {
        if ($app->status !== MarketplaceApp::SUSPENDED) {
            throw new ApiException(409, 'app_not_suspended', __('marketplace.errors.app_not_suspended'));
        }

        $app->forceFill(['status' => $app->archived_at === null ? MarketplaceApp::ACTIVE : MarketplaceApp::ARCHIVED, 'suspended_reason' => null, 'suspended_at' => null, 'suspended_by' => null])->save();
        $this->audit($staff, 'reinstate', $app, ['reason' => $reason]);
        $this->tellInstalls($app, false, $reason);

        return $app;
    }

    /** One job per tenant with an active install, each audited in that tenant's chain. */
    private function tellInstalls(MarketplaceApp $app, bool $suspended, string $reason): void
    {
        $tenants = DB::connection(TenantContext::CONNECTION)->select('select t as id from public.app_tenants_with_app_installed(?) t', [$app->id]);

        foreach ($tenants as $row) {
            AppStatusNotice::dispatch((string) $row->id, $app->id, $suspended, $reason);
        }
    }

    /** @param  array<string, mixed>  $details */
    private function audit(PlatformUser $staff, string $decision, MarketplaceApp|MarketplaceAppVersion $subject, array $details): void
    {
        $appId = $subject instanceof MarketplaceApp ? $subject->id : $subject->app_id;
        $this->platformAudit->record("platform.marketplace.{$decision}", $subject, null, ['app_id' => $appId, ...$details], $staff);

        $developerTenant = $subject->developer_tenant_id;
        $action = ['approve' => 'approved', 'reject' => 'rejected', 'suspend' => 'suspended', 'reinstate' => 'reinstated'][$decision];

        // The developer sees what was decided about its app, in its own chain (as the system, with the staff id).
        if ($developerTenant !== null) {
            $this->tenants->run($developerTenant, fn () => $this->auditor->record("core.developer_app.{$action}", $subject, null, ['app_id' => $appId, 'staff_id' => $staff->id, ...$details]));
        }
    }

    private function assertSubmitted(MarketplaceAppVersion $version): void
    {
        if ($version->status !== MarketplaceAppVersion::SUBMITTED) {
            throw new ApiException(409, 'version_not_submitted', __('marketplace.errors.version_not_submitted'));
        }
    }
}
