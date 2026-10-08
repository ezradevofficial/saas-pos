<?php

namespace Tests\Feature\Core\Notifications;

use App\Core\Notifications\Digest\Digests;
use App\Core\Notifications\Digest\RecipientTimezone;
use App\Core\Notifications\Jobs\SendDigests;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Notifications\Models\NotificationPreference;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NOT-05: daily and weekly digests, one email per user and period, timed
// at 07:00 in the user's time zone (their first assignment's branch or
// company, else the tenant's first company).
class DigestTest extends TestCase
{
    use BuildsNotifications, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setUpOrganisation();
    }

    private function digestFor($user, string $period): void
    {
        $this->inTenant(fn () => NotificationPreference::create(['user_id' => $user->id, 'event_type' => 'core.notification.test', 'digest' => $period]));
    }

    private function sendAt(string $utc, array $recipients, string $message): void
    {
        $this->travelTo(CarbonImmutable::parse($utc));
        $this->sendTest($recipients, ['message' => $message]);
    }

    private function runDigests(string $utc): int
    {
        $this->travelTo(CarbonImmutable::parse($utc));

        return $this->inTenant(fn () => app(Digests::class)->sendDue(now()));
    }

    public function test_a_daily_digest_carries_the_emails_held_before_seven_in_the_users_zone(): void
    {
        $this->digestFor($this->owner, 'daily');
        // Acme is in Africa/Nairobi (UTC+3): 07:00 there is 04:00 UTC.
        $this->sendAt('2026-10-08T05:00:00Z', [$this->owner], 'Morning count');
        $this->sendAt('2026-10-08T20:00:00Z', [$this->owner], 'Evening count');
        Mail::assertNothingSent();

        $this->assertSame(0, $this->runDigests('2026-10-09T03:30:00Z'), '06:30 in Nairobi: not yet');
        $this->assertSame(1, $this->runDigests('2026-10-09T04:30:00Z'));
        $this->sendAt('2026-10-09T05:00:00Z', [$this->owner], 'Next day');
        $this->assertSame(0, $this->runDigests('2026-10-09T09:00:00Z'), 'once per period; later emails wait for tomorrow');

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) {
            $this->assertSame('Your daily summary: 2 notifications', $mail->mailSubject);
            $this->assertStringContainsString('Here is what happened since your last summary.', $mail->text);
            $this->assertStringContainsString('8 Oct 08:00  Test message from Amina', $mail->text);
            $this->assertStringContainsString('8 Oct 23:00  Test message from Amina', $mail->text);

            return $mail->hasTo($this->owner->email);
        });
        Mail::assertSent(NotificationMail::class, 1);

        $this->inTenant(function () {
            $digest = NotificationDelivery::where('event_type', 'core.notification.digest')->sole();
            $this->assertSame(['email', 'sent', 'daily', 1], [$digest->channel, $digest->status, $digest->digest, $digest->attempts]);
            $items = NotificationDelivery::where('digest_id', $digest->id)->get();
            $this->assertCount(2, $items);
            $this->assertTrue($items->every(fn ($item) => $item->status === 'digested'));
            $this->assertSame(1, NotificationDelivery::where('status', 'pending_digest')->count());
        });
    }

    public function test_a_weekly_digest_goes_out_on_monday_morning_and_users_are_grouped_apart(): void
    {
        $colleague = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->inTenant(fn () => $colleague->forceFill(['locale' => 'fr'])->save());
        $this->digestFor($this->owner, 'weekly');
        $this->digestFor($colleague, 'weekly');

        // Thursday 8 October 2026.
        $this->sendAt('2026-10-08T10:00:00Z', [$this->owner, $colleague], 'Weekly item');
        $this->sendAt('2026-10-09T10:00:00Z', [$this->owner, $colleague], 'Another');

        $this->assertSame(0, $this->runDigests('2026-10-10T05:00:00Z'), 'Saturday: the week is not over');
        $this->assertSame(2, $this->runDigests('2026-10-12T04:30:00Z'), 'Monday 07:30 in Nairobi');

        Mail::assertSent(NotificationMail::class, 2);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($colleague->email)
            && $mail->mailSubject === 'Votre résumé de la semaine : 2 notifications' && $mail->locale === 'fr');
    }

    public function test_the_time_zone_comes_from_the_first_assignment_then_the_first_company(): void
    {
        $this->inTenant(fn () => $this->branchB->forceFill(['timezone' => 'Africa/Kinshasa'])->save());
        $kinshasa = $this->userWith('cashier', Scope::location($this->locationB->id));
        $nairobi = $this->userWith('cashier', Scope::location($this->locationA->id));
        $unassigned = $this->inTenant(fn () => $this->colleague($this->owner));
        $zones = app(RecipientTimezone::class);

        $this->inTenant(function () use ($zones, $kinshasa, $nairobi, $unassigned) {
            $this->assertSame('Africa/Kinshasa', $zones->for($kinshasa));
            $this->assertSame('Africa/Nairobi', $zones->for($nairobi), 'a branch without its own zone uses its company');
            $this->assertSame('Africa/Nairobi', $zones->for($unassigned));
            $this->assertSame('Africa/Nairobi', $zones->for($this->owner), 'tenant-wide: the first company');
        });

        // Kinshasa is UTC+1: 07:00 there is 06:00 UTC.
        $this->digestFor($kinshasa, 'daily');
        $this->sendAt('2026-10-08T12:00:00Z', [$kinshasa], 'Item');
        $this->assertSame(0, $this->runDigests('2026-10-09T05:30:00Z'));
        $this->assertSame(1, $this->runDigests('2026-10-09T06:30:00Z'));
    }

    public function test_a_user_without_an_email_any_more_gets_a_skipped_digest(): void
    {
        $colleague = $this->reachableColleague();
        $this->digestFor($colleague, 'daily');
        $this->sendAt('2026-10-08T05:00:00Z', [$colleague], 'Item');
        $this->inTenant(fn () => $colleague->forceFill(['email' => null, 'email_verified_at' => null])->save());

        $this->assertSame(1, $this->runDigests('2026-10-09T04:30:00Z'));

        Mail::assertNothingSent();
        $this->inTenant(function () {
            $digest = NotificationDelivery::where('event_type', 'core.notification.digest')->sole();
            $this->assertSame(['skipped', 'no_email'], [$digest->status, $digest->reason]);
            $this->assertSame(0, NotificationDelivery::where('status', 'pending_digest')->count());
        });
    }

    public function test_a_deactivated_users_held_emails_are_skipped_not_digested(): void
    {
        $colleague = $this->reachableColleague();
        $this->digestFor($colleague, 'daily');
        $this->sendAt('2026-10-08T05:00:00Z', [$colleague], 'Item');
        $this->inTenant(fn () => $colleague->forceFill(['status' => 'deactivated'])->save());

        $this->assertSame(0, $this->runDigests('2026-10-09T04:30:00Z'));

        Mail::assertNothingSent();
        $this->inTenant(function () {
            $this->assertSame(0, NotificationDelivery::where('event_type', 'core.notification.digest')->count());
            $item = NotificationDelivery::where('channel', 'email')->sole();
            $this->assertSame(['skipped', 'user_deactivated'], [$item->status, $item->reason]);
        });
    }

    public function test_items_another_run_has_taken_are_never_digested_twice(): void
    {
        $this->digestFor($this->owner, 'daily');
        $this->sendAt('2026-10-08T05:00:00Z', [$this->owner], 'One');
        $this->sendAt('2026-10-08T06:00:00Z', [$this->owner], 'Two');
        // A concurrent run takes one of the items between this run's read and its lock.
        $taken = false;
        NotificationDelivery::retrieved(function (NotificationDelivery $item) use (&$taken) {
            if (! $taken && $item->status === 'pending_digest') {
                $taken = true;
                NotificationDelivery::query()->whereKey($item->id)->update(['status' => 'digested']);
            }
        });

        $this->assertSame(0, $this->runDigests('2026-10-09T04:30:00Z'));

        Mail::assertNothingSent();
        $this->inTenant(function () {
            $this->assertSame(0, NotificationDelivery::where('event_type', 'core.notification.digest')->count());
            $this->assertSame(1, NotificationDelivery::where('status', 'pending_digest')->count(), 'the rest waits for the next run');
        });
    }

    public function test_digest_runs_are_unique_per_tenant(): void
    {
        $job = new SendDigests($this->owner->tenant_id, now()->toIso8601String());

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame($this->owner->tenant_id, $job->uniqueId());
        $this->assertSame(3600, $job->uniqueFor);
    }

    public function test_the_digest_job_runs_in_its_tenant_only(): void
    {
        $other = $this->otherTenant();
        $this->digestFor($this->owner, 'daily');
        $this->sendAt('2026-10-08T05:00:00Z', [$this->owner], 'Item');
        $this->travelTo(CarbonImmutable::parse('2026-10-09T04:30:00Z'));

        // A job for the other tenant sees nothing of this one.
        $job = new SendDigests($other['user']->tenant_id, now()->toIso8601String());
        app(TenantContext::class)->set($this->owner->tenant_id);
        (new TenantAware)->handle($job, fn ($job) => app()->call([$job, 'handle']));
        Mail::assertNothingSent();
        $this->assertSame($this->owner->tenant_id, app(TenantContext::class)->id());

        $job = new SendDigests($this->owner->tenant_id, now()->toIso8601String());
        app(TenantContext::class)->set($other['user']->tenant_id);
        (new TenantAware)->handle($job, fn ($job) => app()->call([$job, 'handle']));
        Mail::assertSent(NotificationMail::class, 1);
    }
}
