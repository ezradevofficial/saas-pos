<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\CreditLimits\ApplyCreditLimitChange;
use App\Core\MasterData\CreditLimits\CreditLimitChange;
use App\Core\MasterData\CreditLimits\Listeners\SettleCreditLimitChange;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Listeners\SendWorkflowNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * M4 (WF-10, WF-11): settling a credit limit change and sending workflow
 * notifications are queued listeners (after commit), so a failure there
 * never fails the approver's request; `credit-limits:reconcile` settles
 * changes still pending whose flow ended. The command reads tenant ids as
 * the schema owner, which cannot see uncommitted rows, so this test
 * commits and the next test migrates afresh.
 */
class CreditLimitReconcileTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $this->travelBack();
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_the_listeners_are_queued_and_a_missed_settlement_is_reconciled(): void
    {
        foreach ([SettleCreditLimitChange::class, SendWorkflowNotification::class] as $listener) {
            $this->assertContains(ShouldQueue::class, class_implements($listener));
            $this->assertTrue((new \ReflectionClass($listener))->getDefaultProperties()['afterCommit']);
        }

        Mail::fake();
        $this->setUpOrganisation();
        $this->inTenant(fn () => app(TenantCurrencies::class)->activate('KES'));
        $manager = $this->inTenant(function () {
            $user = $this->colleague($this->owner, ['name' => 'Mary Manager']);
            $this->assign($user, $this->roles->get('branch_manager'), Scope::branch($this->branchA->id));

            return $user;
        });
        $accountant = $this->inTenant(function () {
            $user = $this->colleague($this->owner, ['name' => 'Ann Accountant']);
            $this->assign($user, $this->roles->get('accountant'), Scope::tenant());

            return $user;
        });
        $party = $this->postJson('/api/v1/parties', [
            'kind' => 'organisation', 'name' => 'Duka Moja Ltd', 'roles' => ['customer'],
            'credit_limit' => '150000.00', 'credit_limit_currency' => 'KES',
        ], $this->headersFor())->assertCreated()->json('data.id');
        $change = $this->postJson('/api/v1/credit-limit-changes', [
            'party_id' => $party, 'requested_limit' => ['amount_minor' => '25000000', 'currency' => 'KES'], 'reason' => 'More orders',
        ], $this->headersFor($manager))->assertCreated()->json('data.id');
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $change)->sole());

        // The settle listener is queued and never runs (say, its worker died): the approver still gets 200.
        Queue::fake();
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($accountant))->assertOk();
        Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === SettleCreditLimitChange::class);
        $status = fn () => $this->inTenant(fn () => CreditLimitChange::query()->findOrFail($change)->status);
        $this->assertSame('pending', $status());

        // Within the grace period the reconcile pass leaves it to the listener.
        $this->artisan('credit-limits:reconcile')->assertSuccessful();
        $this->assertSame('pending', $status());

        $this->travel(6)->minutes();
        $this->artisan('credit-limits:reconcile')->assertSuccessful();
        $this->assertSame('approved', $status());
        $this->assertSame($accountant->id, $this->inTenant(fn () => CreditLimitChange::query()->findOrFail($change)->decided_by));
        Queue::assertPushed(ApplyCreditLimitChange::class, fn (ApplyCreditLimitChange $job) => $job->changeId === $change);

        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'credit-limits:reconcile'));
        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->onOneServer);
    }
}
