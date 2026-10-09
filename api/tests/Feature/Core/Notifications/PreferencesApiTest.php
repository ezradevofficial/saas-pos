<?php

namespace Tests\Feature\Core\Notifications;

use App\Core\Audit\AuditEntry;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Notifications\Models\NotificationPreference;
use App\Core\Notifications\Models\NotificationSetting;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NOT-04: each user's channels per event type; admins make channels
// mandatory (core.notification_settings.edit) and users cannot switch
// those off. NOT-05: email timing. NOT-02: the event types listed.
class PreferencesApiTest extends TestCase
{
    use BuildsNotifications, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setUpOrganisation();
        $this->registerTestEventTypes();
    }

    private function preference(array $body, ?array $headers = null)
    {
        return $this->putJson('/api/v1/me/notification-preferences', ['preferences' => [$body]], $headers ?? $this->headersFor());
    }

    private function entry(array $data, string $type): array
    {
        return collect($data)->firstWhere('event_type', $type);
    }

    public function test_preferences_start_from_the_event_defaults_and_change_per_channel(): void
    {
        $data = $this->getJson('/api/v1/me/notification-preferences', $this->headersFor())->assertOk()->json('data');
        $this->assertSame([
            'core.approval.attention', 'core.approval.decided', 'core.approval.delegated', 'core.approval.escalated', 'core.approval.info_requested',
            'core.approval.reminder', 'core.approval.requested', 'core.approval.returned',
            'core.automation.failed', 'core.automation.notify', 'core.credit_limit_change.apply_failed', 'core.credit_limit_change.conflicted', 'core.domain.lost',
            'core.fiscal.delayed', 'core.fiscal.needs_attention', 'core.fiscal.rejected',
            'core.identity.new_device', 'core.notification.test', 'core.report.ready', 'core.workflow.notify', 'core.workflow.stage_overdue', 'core.workflow.stage_reminder',
        ], array_column($data, 'event_type'));

        $test = $this->entry($data, 'core.notification.test');
        $this->assertSame('Test message', $test['label']);
        $this->assertSame('immediate', $test['digest']);
        $this->assertTrue($test['digest_allowed']);
        $this->assertSame(
            ['in_app' => true, 'email' => true, 'push' => false, 'sms' => false, 'whatsapp' => false],
            collect($test['channels'])->pluck('enabled', 'channel')->all(),
        );

        $data = $this->preference(['event_type' => 'core.notification.test', 'channels' => ['email' => false, 'sms' => true], 'digest' => 'weekly'])
            ->assertOk()->json('data');
        $test = $this->entry($data, 'core.notification.test');
        $this->assertSame(['in_app' => true, 'email' => false, 'push' => false, 'sms' => true, 'whatsapp' => false], collect($test['channels'])->pluck('enabled', 'channel')->all());
        $this->assertSame('weekly', $test['digest']);

        // Channels left out keep their setting.
        $data = $this->preference(['event_type' => 'core.notification.test', 'channels' => ['email' => true]])->assertOk()->json('data');
        $test = $this->entry($data, 'core.notification.test');
        $this->assertTrue(collect($test['channels'])->firstWhere('channel', 'sms')['enabled']);
        $this->assertSame('weekly', $test['digest']);

        $this->inTenant(function () {
            $this->assertSame(1, NotificationPreference::count());
            $this->assertSame(['core.notification_preference.create', 'core.notification_preference.update'],
                AuditEntry::where('action', 'like', 'core.notification_preference.%')->orderBy('seq')->pluck('action')->all());
        });

        // The colleague's preferences are their own.
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $theirs = $this->getJson('/api/v1/me/notification-preferences', $this->headersFor($cashier))->assertOk()->json('data');
        $this->assertFalse(collect($this->entry($theirs, 'core.notification.test')['channels'])->firstWhere('channel', 'sms')['enabled']);
    }

    public function test_the_digest_choice_holds_email_and_in_app_stays_immediate(): void
    {
        $this->preference(['event_type' => 'core.notification.test', 'digest' => 'daily'])->assertOk();

        $this->sendTest([$this->owner]);

        $this->inTenant(function () {
            $email = NotificationDelivery::where('channel', 'email')->sole();
            $this->assertSame(['pending_digest', 'daily'], [$email->status, $email->digest]);
            $this->assertSame('delivered', NotificationDelivery::where('channel', 'in_app')->sole()->status);
        });
        Mail::assertNothingSent();
    }

    public function test_admins_make_channels_mandatory_and_users_cannot_switch_them_off(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $body = ['event_types' => [['event_type' => 'core.approval.requested', 'mandatory_channels' => ['in_app', 'email']]]];

        // Only Owner and Admin hold core.notification_settings.edit.
        $this->putJson('/api/v1/notification-settings', $body, $this->headersFor($manager))->assertForbidden();
        $admin = $this->userWith('admin', Scope::tenant());
        $data = $this->putJson('/api/v1/notification-settings', $body, $this->headersFor($admin))->assertOk()->json('data');
        $this->assertSame(['in_app', 'email'], $this->entry($data, 'core.approval.requested')['mandatory_channels']);

        // Everyone reads the event types with the mandatory flags.
        $types = $this->getJson('/api/v1/notification-event-types', $this->headersFor($manager))->assertOk()->json('data');
        $approval = $this->entry($types, 'core.approval.requested');
        $this->assertTrue($approval['mandatory_allowed']);
        $this->assertTrue(collect($approval['channels'])->firstWhere('channel', 'email')['mandatory']);
        $this->assertFalse($this->entry($types, 'core.report.ready')['mandatory_allowed']);
        $this->assertContains('document_number', array_column($approval['placeholders'], 'name'));

        // A user cannot switch a mandatory channel off, nor hold its email for a digest.
        $this->preference(['event_type' => 'core.approval.requested', 'channels' => ['email' => false]], $this->headersFor($manager))
            ->assertUnprocessable()->assertJsonValidationErrors('preferences.0.channels.email');
        $this->preference(['event_type' => 'core.approval.requested', 'digest' => 'daily'], $this->headersFor($manager))
            ->assertUnprocessable()->assertJsonValidationErrors('preferences.0.digest');
        $this->preference(['event_type' => 'core.approval.requested', 'channels' => ['sms' => true, 'email' => true]], $this->headersFor($manager))->assertOk();

        $mine = $this->entry($this->getJson('/api/v1/me/notification-preferences', $this->headersFor($manager))->json('data'), 'core.approval.requested');
        $this->assertFalse($mine['digest_allowed']);
        $this->assertTrue(collect($mine['channels'])->firstWhere('channel', 'in_app')['mandatory']);

        // Turning it off again: an empty list.
        $this->putJson('/api/v1/notification-settings', ['event_types' => [['event_type' => 'core.approval.requested', 'mandatory_channels' => []]]], $this->headersFor())
            ->assertOk();
        $this->preference(['event_type' => 'core.approval.requested', 'channels' => ['email' => false]], $this->headersFor($manager))->assertOk();

        $this->inTenant(fn () => $this->assertSame(
            ['core.notification_setting.create', 'core.notification_setting.update'],
            AuditEntry::where('action', 'like', 'core.notification_setting.%')->orderBy('seq')->pluck('action')->all(),
        ));
    }

    public function test_settings_are_validated(): void
    {
        $put = fn (array $entries) => $this->putJson('/api/v1/notification-settings', ['event_types' => $entries], $this->headersFor());

        $put([['event_type' => 'core.report.ready', 'mandatory_channels' => ['in_app']]])->assertUnprocessable()->assertJsonValidationErrors('event_types.0.mandatory_channels');
        $put([['event_type' => 'core.report.ready', 'mandatory_channels' => []]])->assertOk();
        $put([['event_type' => 'core.approval.requested', 'mandatory_channels' => ['fax']]])->assertUnprocessable()->assertJsonValidationErrors('event_types.0.mandatory_channels.0');
        $put([['event_type' => 'core.nothing.here', 'mandatory_channels' => []]])->assertUnprocessable()->assertJsonValidationErrors('event_types.0.event_type');
        $put([['event_type' => 'core.approval.requested']])->assertUnprocessable()->assertJsonValidationErrors('event_types.0.mandatory_channels');
        $put([])->assertUnprocessable()->assertJsonValidationErrors('event_types');
        $this->inTenant(fn () => $this->assertSame(0, NotificationSetting::count()));
    }

    public function test_preferences_are_validated(): void
    {
        $this->preference(['event_type' => 'core.report.ready', 'channels' => ['sms' => true]])
            ->assertUnprocessable()->assertJsonValidationErrors('preferences.0.channels.sms');
        $this->preference(['event_type' => 'core.notification.test', 'channels' => ['email' => 'maybe']])
            ->assertUnprocessable()->assertJsonValidationErrors('preferences.0.channels.email');
        $this->preference(['event_type' => 'core.notification.test', 'digest' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors('preferences.0.digest');
        $this->preference(['event_type' => 'pos.sale.completed'])
            ->assertUnprocessable()->assertJsonValidationErrors('preferences.0.event_type');
        $this->preference(['event_type' => 'core.notification.test', 'user_id' => $this->owner->id])
            ->assertUnprocessable()->assertJsonValidationErrors('preferences.0');
        $this->putJson('/api/v1/me/notification-preferences', [], $this->headersFor())->assertUnprocessable();
    }

    public function test_a_mandatory_channel_reaches_a_user_who_switched_it_off_before(): void
    {
        $this->preference(['event_type' => 'core.approval.requested', 'channels' => ['email' => false]])->assertOk();
        $this->putJson('/api/v1/notification-settings', ['event_types' => [['event_type' => 'core.approval.requested', 'mandatory_channels' => ['email']]]], $this->headersFor())->assertOk();

        $this->sendTest([$this->owner], ['document_number' => 'PO-1'], 'core.approval.requested');

        Mail::assertSent(NotificationMail::class, 1);
    }

    public function test_settings_and_preferences_of_another_tenant_are_untouched(): void
    {
        $other = $this->otherTenant();
        $headers = $this->bearer($this->tokenFor($other['user']));
        $this->putJson('/api/v1/notification-settings', ['event_types' => [['event_type' => 'core.approval.requested', 'mandatory_channels' => ['email']]]], $this->headersFor())->assertOk();
        $this->preference(['event_type' => 'core.notification.test', 'channels' => ['email' => false]])->assertOk();

        $types = $this->getJson('/api/v1/notification-event-types', $headers)->assertOk()->json('data');
        $this->assertSame([], $this->entry($types, 'core.approval.requested')['mandatory_channels']);
        $mine = $this->getJson('/api/v1/me/notification-preferences', $headers)->assertOk()->json('data');
        $this->assertTrue(collect($this->entry($mine, 'core.notification.test')['channels'])->firstWhere('channel', 'email')['enabled']);
        $this->preference(['event_type' => 'core.approval.requested', 'channels' => ['email' => false]], $headers)->assertOk();
    }
}
