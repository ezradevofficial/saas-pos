<?php

namespace Tests\Feature\Core\Notifications;

use App\Core\Audit\AuditEntry;
use App\Core\Notifications\Models\NotificationPreference;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\ReadsListExports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NOT-06: the delivery log for admins (core.notification_delivery.view,
// tenant-wide): status per message and channel, filters, search, sort,
// export (EXP-01).
class DeliveryApiTest extends TestCase
{
    use BuildsNotifications, ReadsListExports, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setUpOrganisation();
    }

    public function test_admins_list_filter_search_and_sort_deliveries(): void
    {
        $colleague = $this->inTenant(fn () => $this->colleague($this->owner, ['name' => 'Baraka', 'email' => null, 'email_verified_at' => null, 'phone' => '+254733000123']));
        $this->inTenant(fn () => NotificationPreference::create(['user_id' => $this->owner->id, 'event_type' => 'core.notification.test', 'channels' => ['sms' => true]]));
        $this->sendTest([$this->owner, $colleague]);

        $all = $this->getJson('/api/v1/notification-deliveries', $this->headersFor())->assertOk();
        // Owner: in_app, email, sms (no phone: skipped); colleague: in_app, email (no email: skipped).
        $all->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 5);
        $row = collect($all->json('data'))->first(fn ($d) => $d['channel'] === 'sms');
        $this->assertSame(['skipped', 'no_phone', 'The user has no verified phone number.', 'Owner', 'Test message'], [
            $row['status'], $row['reason'], $row['reason_label'], $row['user']['name'], $row['event_label'],
        ]);
        $this->assertArrayNotHasKey('body', $row, 'message bodies stay out of the log');

        $this->getJson('/api/v1/notification-deliveries?status=skipped', $this->headersFor())->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/notification-deliveries?status=sent&channel=email', $this->headersFor())->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.recipient', $this->owner->email)->assertJsonPath('data.0.attempts', 1);
        $this->getJson('/api/v1/notification-deliveries?channel=in_app', $this->headersFor())->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/notification-deliveries?search=baraka', $this->headersFor())->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/notification-deliveries?search='.urlencode($this->owner->email), $this->headersFor())->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/notification-deliveries?sort=user', $this->headersFor())->assertOk()->assertJsonPath('data.0.user.name', 'Baraka');
        $this->getJson('/api/v1/notification-deliveries?sort=-attempts&per_page=2', $this->headersFor())->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/notification-deliveries?status=lost', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/v1/notification-deliveries?channel=fax', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->getJson('/api/v1/notification-deliveries?sort=body', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_errors_show_as_safe_messages_never_the_raw_provider_error(): void
    {
        $this->inTenant(function () {
            $this->owner->forceFill(['phone' => '+254722000555', 'phone_verified_at' => now()])->save();
            NotificationPreference::create(['user_id' => $this->owner->id, 'event_type' => 'core.notification.test', 'channels' => ['sms' => true, 'email' => false, 'in_app' => false]]);
        });
        $this->fakeDriver('sms')->failNext(3, 'auth token sk_live_secret rejected');
        $this->sendTest([$this->owner]);

        $response = $this->getJson('/api/v1/notification-deliveries', $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.error', 'send_failed')
            ->assertJsonPath('data.0.error_label', 'The mail server or provider refused or didn’t answer.');
        $this->assertStringNotContainsString('sk_live_secret', $response->getContent());
        $csv = $this->get('/api/v1/notification-deliveries?format=csv&columns[]=error', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('The mail server or provider refused', $csv);
        $this->assertStringNotContainsString('sk_live_secret', $csv);
    }

    public function test_the_log_is_exported_and_the_export_audited(): void
    {
        $this->sendTest([$this->owner]);

        $rows = $this->csvRows($this->get('/api/v1/notification-deliveries?format=csv&sort=channel&columns[]=channel&columns[]=status&columns[]=user', $this->headersFor())->assertOk());

        $this->assertSame([['Channel', 'Status', 'Recipient'], ['Email', 'Sent', 'Owner'], ['In-app', 'Delivered', 'Owner']], $rows);
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.notification_delivery.export')->count()));
        $this->get('/api/v1/notification-deliveries?format=xlsx', $this->headersFor())->assertOk();
    }

    public function test_the_log_needs_the_permission_at_tenant_scope(): void
    {
        $this->sendTest([$this->owner]);

        $this->getJson('/api/v1/notification-deliveries', $this->headersFor($this->userWith('branch_manager', Scope::branch($this->branchA->id))))->assertForbidden();
        $this->getJson('/api/v1/notification-deliveries', $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id))))->assertForbidden();
        $this->getJson('/api/v1/notification-deliveries', $this->headersFor($this->userWith('admin', Scope::company($this->acme->id))))->assertForbidden();
        $this->getJson('/api/v1/notification-deliveries', $this->headersFor($this->userWith('admin', Scope::tenant())))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/notification-deliveries')->assertUnauthorized();
    }

    public function test_another_tenant_sees_only_its_own_deliveries(): void
    {
        $this->sendTest([$this->owner]);
        $other = $this->otherTenant();
        $headers = $this->bearer($this->tokenFor($other['user']));

        $response = $this->getJson('/api/v1/notification-deliveries?status=all', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->assertStringNotContainsString($this->owner->email, $response->getContent());
        $this->assertCount(1, $this->csvRows($this->get('/api/v1/notification-deliveries?format=csv', $headers)->assertOk()), 'the header alone');
    }
}
