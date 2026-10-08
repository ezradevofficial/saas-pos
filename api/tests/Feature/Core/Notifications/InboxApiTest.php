<?php

namespace Tests\Feature\Core\Notifications;

use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\ReadsListExports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NOT-01: the bell and inbox: the user's own notifications only, unread
// count, mark read, mark all read, archive; list framework (EXP-01).
class InboxApiTest extends TestCase
{
    use BuildsNotifications, ReadsListExports, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setUpOrganisation();
    }

    public function test_the_inbox_lists_my_notifications_newest_first_with_the_unread_count(): void
    {
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->travelTo(now()->subHour());
        $this->sendTest([$this->owner, $cashier], ['message' => 'First']);
        $this->travelBack();
        $this->sendTest([$this->owner], ['message' => 'Second']);

        $response = $this->getJson('/api/v1/notifications', $this->headersFor())->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.unread', 2)
            ->assertJsonPath('data.0.event_type', 'core.notification.test')
            ->assertJsonPath('data.0.event_label', 'Test message')
            ->assertJsonPath('data.0.read_at', null);
        $this->assertStringContainsString('Second', $response->json('data.0.body'));

        // A cashier, with no permission at all on notifications, has an inbox too: only their own.
        $this->getJson('/api/v1/notifications', $this->headersFor($cashier))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/notifications/unread-count', $this->headersFor($cashier))->assertOk()->assertExactJson(['data' => ['unread' => 1]]);
    }

    public function test_read_read_all_and_archive_change_only_my_notifications(): void
    {
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->sendTest([$this->owner, $cashier]);
        $this->sendTest([$this->owner, $cashier]);
        [$first, $second] = $this->inTenant(fn () => InAppNotification::where('user_id', $this->owner->id)->orderBy('id')->pluck('id')->all());
        $theirs = $this->inTenant(fn () => InAppNotification::where('user_id', $cashier->id)->value('id'));

        $this->postJson("/api/v1/notifications/{$first}/read", [], $this->headersFor())->assertOk()->assertJsonPath('data.id', $first);
        $this->inTenant(fn () => $this->assertNotNull(InAppNotification::findOrFail($first)->read_at));
        $this->getJson('/api/v1/notifications/unread-count', $this->headersFor())->assertJsonPath('data.unread', 1);
        $this->getJson('/api/v1/notifications?status=unread', $this->headersFor())->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second);

        // Someone else's notification is not found, and stays unread.
        $this->postJson("/api/v1/notifications/{$theirs}/read", [], $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/notifications/{$theirs}/archive", [], $this->headersFor())->assertNotFound();
        $this->postJson('/api/v1/notifications/not-a-uuid/read', [], $this->headersFor())->assertNotFound();

        $this->postJson('/api/v1/notifications/read-all', [], $this->headersFor())->assertOk()->assertJsonPath('data.updated', 1);
        $this->getJson('/api/v1/notifications/unread-count', $this->headersFor())->assertJsonPath('data.unread', 0);
        $this->getJson('/api/v1/notifications/unread-count', $this->headersFor($cashier))->assertJsonPath('data.unread', 2);

        $this->postJson("/api/v1/notifications/{$second}/archive", [], $this->headersFor())->assertOk()->assertJsonPath('data.id', $second);
        $this->getJson('/api/v1/notifications', $this->headersFor())->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);
        $this->getJson('/api/v1/notifications?status=archived', $this->headersFor())->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second);
        $this->getJson('/api/v1/notifications?status=all', $this->headersFor())->assertJsonCount(2, 'data');
    }

    public function test_archiving_an_unread_notification_takes_it_off_the_count(): void
    {
        $this->sendTest([$this->owner]);
        $id = $this->inTenant(fn () => InAppNotification::value('id'));

        $this->postJson("/api/v1/notifications/{$id}/archive", [], $this->headersFor())->assertOk();

        $this->getJson('/api/v1/notifications/unread-count', $this->headersFor())->assertJsonPath('data.unread', 0);
        $this->assertNotNull($this->getJson('/api/v1/notifications?status=archived', $this->headersFor())->json('data.0.read_at'));
    }

    public function test_search_sort_paging_and_export(): void
    {
        $this->sendTest([$this->owner], ['message' => 'Delivery from Kisumu']);
        $this->sendTest([$this->owner], ['message' => 'Stock count']);

        $this->getJson('/api/v1/notifications?search=kisumu', $this->headersFor())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/notifications?per_page=1', $this->headersFor())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/notifications?sort=subject', $this->headersFor())->assertOk();
        $this->getJson('/api/v1/notifications?sort=body', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/notifications?status=deleted', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('status');

        $rows = $this->csvRows($this->get('/api/v1/notifications?format=csv&columns[]=subject&columns[]=read', $this->headersFor())->assertOk());
        $this->assertSame(['Subject', 'Read'], $rows[0]);
        $this->assertSame(['Test message from Amina', 'No'], $rows[1]);
        $this->assertCount(3, $rows);
    }

    public function test_another_tenant_sees_none_of_it(): void
    {
        $this->sendTest([$this->owner]);
        $id = $this->inTenant(fn () => InAppNotification::value('id'));
        $other = $this->otherTenant();
        $headers = $this->bearer($this->tokenFor($other['user']));

        $this->getJson('/api/v1/notifications?status=all', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/notifications/unread-count', $headers)->assertJsonPath('data.unread', 0);
        $this->postJson("/api/v1/notifications/{$id}/read", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/notifications/{$id}/archive", [], $headers)->assertNotFound();
        $this->postJson('/api/v1/notifications/read-all', [], $headers)->assertOk()->assertJsonPath('data.updated', 0);
        $this->inTenant(fn () => $this->assertNull(InAppNotification::findOrFail($id)->read_at));
    }

    public function test_the_inbox_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
    }
}
