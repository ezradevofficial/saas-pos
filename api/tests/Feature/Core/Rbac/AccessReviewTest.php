<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-11: who holds which role where, granted by whom and when.
class AccessReviewTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    /** @return list<list<string>> */
    private function csv(string $content): array
    {
        $rows = [];
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function test_the_csv_export_has_a_header_and_one_row_per_assignment(): void
    {
        $this->userWith('cashier', Scope::location($this->locationA->id));
        $tricky = $this->userWith('cashier', Scope::location($this->locationB->id));
        $this->inTenant(fn () => $tricky->forceFill(['name' => '=HYPERLINK("x"), "Bob"'])->save());
        $this->otherTenant();

        $response = $this->get('/api/v1/access-review?format=csv', $this->headersFor())->assertOk();

        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=access-review-'.now()->format('Y-m-d').'.csv', $response->headers->get('Content-Disposition'));

        $rows = $this->csv($response->streamedContent());
        $this->assertSame(['user_name', 'user_contact', 'user_status', 'role', 'scope_type', 'scope_name', 'granted_by', 'granted_at'], $rows[0]);
        $this->assertCount(1 + 3, $rows);

        $owner = collect($rows)->firstWhere(0, 'Owner');
        $this->assertSame([$this->owner->email, 'active', 'Owner', 'tenant'], array_slice($owner, 1, 4));

        $outletA = collect($rows)->firstWhere(5, 'Outlet A');
        $this->assertSame(['Cashier', 'location'], array_slice($outletA, 3, 2));

        // Formula injection is neutralised; quotes and commas survive.
        $this->assertSame("'=HYPERLINK(\"x\"), \"Bob\"", collect($rows)->firstWhere(5, 'Outlet B')[0]);

        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.access_review.export')->where('after->rows', 3)->count()));
    }

    public function test_the_json_review_is_paginated_and_names_who_granted(): void
    {
        $user = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->inTenant(fn () => RoleAssignment::where('user_id', $user->id)->update(['created_by' => $this->owner->id]));

        $this->getJson('/api/v1/access-review?per_page=1', $this->headersFor())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2);

        $row = collect($this->getJson('/api/v1/access-review', $this->headersFor())->json('data'))->firstWhere('user.id', $user->id);
        $this->assertSame('Cashier', $row['role']['name']);
        $this->assertSame(['type' => 'location', 'id' => $this->locationA->id, 'name' => 'Outlet A'], $row['scope']);
        $this->assertSame('Owner', $row['granted_by']['name']);
        $this->assertNotNull($row['granted_at']);
    }

    public function test_a_scoped_auditor_sees_only_assignments_within_their_scope_and_cannot_export(): void
    {
        $inA = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->userWith('cashier', Scope::location($this->locationB->id));
        $auditor = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Branch auditor', ['core.access_review.view']), Scope::branch($this->branchA->id));

            return $user;
        });

        $users = collect($this->getJson('/api/v1/access-review', $this->headersFor($auditor))->assertOk()->json('data'))->pluck('user.id');
        $this->assertEqualsCanonicalizing([$inA->id, $auditor->id], $users->all());

        $this->get('/api/v1/access-review?format=csv', $this->headersFor($auditor))->assertForbidden();
        $this->getJson('/api/v1/access-review', $this->headersFor($inA))->assertForbidden();
    }
}
