<?php

namespace Tests\Feature\Core\Approvals;

use App\Core\Approvals\Jobs\ProcessApprovalTimers;
use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalDelegation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsApprovals;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * TEN-01 for the approval tables: references to users, requests and
 * assignments are composite (tenant_id, id) foreign keys, so a row can
 * never point at another tenant's user even when the id is known.
 */
class ApprovalIntegrityTest extends TestCase
{
    use BuildsApprovals, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpApprovals();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_an_assignment_cannot_name_another_tenants_user(): void
    {
        $approval = $this->submit($this->approvalGraph());
        $other = $this->otherTenant();

        $this->expectException(QueryException::class);
        $this->inTenant(fn () => ApprovalAssignment::create([
            'request_id' => $approval->id, 'user_id' => $other['user']->id, 'step' => 0, 'source' => 'resolved', 'status' => 'pending',
        ]));
    }

    /** M4: decisions lock the flow row before the request, as the engine does, so they never deadlock with a move. */
    public function test_decisions_and_timers_lock_the_flow_before_the_request(): void
    {
        $tables = function (callable $act): array {
            $locked = [];
            DB::listen(function ($query) use (&$locked) {
                if (str_contains($query->sql, 'for update') && preg_match('/from "(\w+)"/', $query->sql, $m) === 1) {
                    $locked[] = $m[1];
                }
            });
            $act();

            return array_values(array_unique($locked));
        };

        $approval = $this->submit($this->approvalGraph());
        $order = $tables(fn () => $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->managerA))->assertOk());
        $this->assertSame(['document_workflows', 'approval_requests'], array_slice($order, 0, 2));

        $other = $this->submit($this->approvalGraph(), publish: false);
        $order = $tables(fn () => $this->postJson($this->approvalUrl($other, '/return'), ['node' => 'prepare', 'reason' => 'Fix'], $this->headersFor($this->managerA))->assertOk());
        $this->assertSame(['document_workflows', 'approval_requests'], array_slice($order, 0, 2));

        $timed = $this->submit($this->approvalGraph([], ['reminders' => [['amount' => 1, 'unit' => 'hours']]]));
        $order = $tables(fn () => ProcessApprovalTimers::dispatchSync($this->owner->tenant_id, '2026-10-07T09:00:00Z'));
        $this->assertSame(['document_workflows', 'approval_requests'], array_slice($order, 0, 2));
        $this->assertSame(1, $this->fresh($timed)->reminders_sent);
    }

    public function test_a_delegation_cannot_name_another_tenants_user(): void
    {
        $other = $this->otherTenant();

        $this->expectException(QueryException::class);
        $this->inTenant(fn () => ApprovalDelegation::create([
            'from_user_id' => $this->managerA->id, 'to_user_id' => $other['user']->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-08', 'created_by' => $this->managerA->id,
        ]));
    }
}
