<?php

namespace Modules\CRM\Tests;

use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Tenancy\Models\Branch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Team;
use Modules\CRM\Models\TeamMember;
use Modules\CRM\Tests\Concerns\BuildsCrm;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CRM-26 to CRM-31: activities with reminders through the core
// notifications, the calendar range, emails through the core mailer and
// logged, sales targets with actuals from won opportunities (by owner,
// team and branch), and the dashboard sources.
class ActivitiesAndTargetsTest extends TestCase
{
    use BuildsCrm, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrm();
    }

    public function test_a_reminder_is_sent_once_to_the_owner_when_due(): void
    {
        $activity = $this->api('POST', 'crm/activities', ['branch_id' => $this->branchA->id, 'kind' => 'meeting', 'subject' => 'Site visit',
            'starts_at' => '2026-10-15T12:00:00Z', 'remind_at' => '2026-10-15T11:30:00Z', 'party_id' => $this->customer->id], $this->repA)->assertCreated()->json('data');
        $this->assertSame([$this->repA->id, 'open', false], [$activity['owner']['id'], $activity['status'], $activity['overdue']]);

        Artisan::call('crm:remind');
        $this->assertSame(0, $this->inTenant(fn () => InAppNotification::query()->count()), 'not due yet');

        $this->clockTo('2026-10-15T11:31:00Z');
        Artisan::call('crm:remind');
        Artisan::call('crm:remind');
        $sent = $this->inTenant(fn () => InAppNotification::query()->get());
        $this->assertCount(1, $sent);
        $this->assertSame($this->repA->id, $sent[0]->user_id);
        $this->assertStringContainsString('Site visit', (string) $sent[0]->subject);

        // Moving the reminder arms it again; a done activity is not reminded.
        $this->api('PATCH', "crm/activities/{$activity['id']}", ['remind_at' => '2026-10-15T11:45:00Z'], $this->repA)->assertOk()->assertJsonPath('data.reminded_at', null);
        $this->api('POST', "crm/activities/{$activity['id']}/complete", ['outcome' => 'Agreed'], $this->repA)->assertOk()->assertJsonPath('data.status', 'done');
        $this->clockTo('2026-10-15T11:50:00Z');
        Artisan::call('crm:remind');
        $this->assertSame(1, $this->inTenant(fn () => InAppNotification::query()->count()));
    }

    public function test_the_calendar_lists_a_date_range_and_an_email_is_sent_and_logged(): void
    {
        foreach (['2026-10-01T08:00:00Z', '2026-10-20T08:00:00Z', '2026-11-02T08:00:00Z'] as $at) {
            $this->api('POST', 'crm/activities', ['branch_id' => $this->branchA->id, 'kind' => 'call', 'subject' => "Call {$at}", 'starts_at' => $at], $this->repA)->assertCreated();
        }

        $october = $this->api('GET', 'crm/activities?from=2026-10-01&to=2026-10-31&kind=call', [], $this->repA)->assertOk()->json('data');
        $this->assertSame(['Call 2026-10-01T08:00:00Z', 'Call 2026-10-20T08:00:00Z'], array_column($october, 'subject'));
        $this->assertTrue($october[0]['overdue']);

        $email = $this->api('POST', 'crm/emails', ['to' => 'buyer@duka.example.com', 'subject' => 'Your quote', 'body' => 'Please find the details.', 'party_id' => $this->customer->id, 'branch_id' => $this->branchA->id], $this->repA)
            ->assertCreated()->json('data');
        $this->assertSame(['email', 'done', 'buyer@duka.example.com'], [$email['kind'], $email['status'], $email['email']['to']]);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('buyer@duka.example.com') && $mail->mailSubject === 'Your quote');
        $this->assertSame('sent', $this->inTenant(fn () => Activity::query()->findOrFail($email['id'])->email['sent']));
    }

    public function test_won_value_targets_count_the_owner_the_team_and_the_branch(): void
    {
        $this->inTenant(function () {
            $team = Team::query()->create(['company_id' => $this->acme->id, 'name' => 'Field', 'leader_id' => $this->leader->id]);
            TeamMember::query()->create(['team_id' => $team->id, 'user_id' => $this->repA->id]);
            TeamMember::query()->create(['team_id' => $team->id, 'user_id' => $this->repA2->id]);
        });
        $teamId = $this->inTenant(fn () => Team::query()->sole()->id);
        $win = function ($as, string $value, string $branch, ?string $currency = 'KES') {
            $opportunity = $this->api('POST', 'crm/opportunities', ['branch_id' => $branch, 'party_id' => $this->customer->id, 'name' => 'Deal', 'value_minor' => $value, 'currency' => $currency], $as)
                ->assertCreated()->json('data');
            $this->api('POST', "crm/opportunities/{$opportunity['id']}/win", [], $as)->assertOk();
        };
        $win($this->repA, '300000', $this->branchA->id);
        $win($this->repA2, '200000', $this->branchA->id);
        $win($this->repA, '1000', $this->branchA->id, 'USD'); // USD 10.00: KES 1,300.00 at 130
        $win($this->repB, '700000', $this->branchB->id);

        $target = fn (string $type, string $id, string $amount) => $this->api('POST', 'crm/targets', ['company_id' => $this->acme->id, 'subject_type' => $type, 'subject_id' => $id,
            'metric' => 'won_value', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount_minor' => $amount])->assertCreated()->json('data');

        $mine = $target('user', $this->repA->id, '1000000');
        $this->assertSame(['430000', '43.0', true], [$mine['actual_minor'], $mine['percent'], $mine['available']]);
        $this->assertSame('630000', $target('team', $teamId, '1000000')['actual_minor']);
        $branch = $target('branch', $this->branchA->id, '500000');
        $this->assertSame(['630000', '126.0', $this->branchA->id], [$branch['actual_minor'], $branch['percent'], $branch['branch']['id']]);
        $this->assertSame('700000', $target('branch', $this->branchB->id, '100')['actual_minor']);

        // Who sees what: rep A their own; the leader (team scope) also the team's; nobody else's.
        $this->assertSame([$mine['id']], array_column($this->api('GET', 'crm/targets', [], $this->repA)->json('data'), 'id'));
        $this->assertCount(4, $this->api('GET', 'crm/targets')->json('data'));
        $this->assertSame([], $this->api('GET', 'crm/targets', [], $this->repB)->json('data'));

        // The dashboard source lists the reader's current targets.
        $rows = $this->api('GET', 'dashboard/sources/crm.targets', [], $this->repA)->assertOk()->json('data.rows');
        $this->assertSame(['Won opportunities', 'KES 4,300.00 of KES 10,000.00 (43.0%)'], [$rows[0]['title'], $rows[0]['subtitle']]);
        $kpi = $this->api('GET', 'dashboard/sources/crm.pipeline_value', [], $this->repA)->assertOk()->json('data');
        $this->assertSame(['0', 'KES'], [$kpi['value'], $kpi['currency']]);

        // Another company's branch is refused as a subject.
        $other = $this->inTenant(fn () => Branch::query()->create(['company_id' => $this->company('Beta')->id, 'name' => 'Beta', 'code' => 'BT']));
        $this->api('POST', 'crm/targets', ['company_id' => $this->acme->id, 'subject_type' => 'branch', 'subject_id' => $other->id, 'metric' => 'won_value',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount_minor' => '1'])->assertUnprocessable()->assertJsonPath('code', 'subject_unknown');
    }
}
