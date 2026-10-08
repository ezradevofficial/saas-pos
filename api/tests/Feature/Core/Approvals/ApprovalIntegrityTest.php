<?php

namespace Tests\Feature\Core\Approvals;

use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalDelegation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
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

    public function test_a_delegation_cannot_name_another_tenants_user(): void
    {
        $other = $this->otherTenant();

        $this->expectException(QueryException::class);
        $this->inTenant(fn () => ApprovalDelegation::create([
            'from_user_id' => $this->managerA->id, 'to_user_id' => $other['user']->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-08', 'created_by' => $this->managerA->id,
        ]));
    }
}
