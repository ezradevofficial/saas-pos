<?php

namespace Modules\Stores\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\Stores\Tests\Concerns\BuildsStores;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// STR-12, STR-13: consumption (issued − returned) by department, cost
// centre, item and month, in the company's time zone, at the reader's
// stores, in quantities without Inventory and with values when Inventory
// valued them (for value holders only).
class ConsumptionTest extends TestCase
{
    use BuildsStores, RefreshTenantDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpStores();
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->opening($this->soap, '100');
        $this->opening($this->sugar, '50');
        $this->opening($this->soap, '20', $this->storeB);
    }

    /** Issue the whole of a new approved requisition at $at (UTC). @return array<string, mixed> the issue */
    private function consume(string $at, array $lines, $department = null, ?string $costCentre = null, $store = null): array
    {
        $this->travelTo(CarbonImmutable::parse($at));
        // Sessions signed in at that time (a session from the future is refused).
        $owner = $this->bearer($this->tokenFor($this->owner));
        $requisition = $this->postJson('/api/v1/stores/requisitions', [
            'location_id' => ($store ?? $this->store)->id, 'department_id' => ($department ?? $this->kitchen)->id, 'lines' => $lines,
            ...($costCentre === null ? [] : ['cost_centre_id' => $costCentre]),
        ], $owner)->assertCreated()->json('data');
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $requisition['id'])->sole());
        // The department head approves; a department without one goes to the store's branch managers.
        $approver = ($department ?? $this->kitchen)->owner_user_id === null ? $this->manager : $this->head;
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->bearer($this->tokenFor($approver)))->assertOk();
        $issue = $this->postJson("/api/v1/stores/requisitions/{$requisition['id']}/issues", [
            'lines' => array_map(fn ($line) => ['requisition_line_id' => $line['id'], 'qty' => $line['qty']], $requisition['lines']),
        ], $owner)->assertCreated()->json('data');
        $this->travelBack();

        return $issue;
    }

    /** @return array<string, array{0: ?string, 1: string, 2: string, 3: string}> "group|item" => [issued, returned, net, net value] */
    private function report(string $query, $user = null): array
    {
        $rows = $this->getJson('/api/v1/stores/consumption?'.$query, $this->headersFor($user))->assertOk()->json('data');
        $out = [];

        foreach ($rows as $row) {
            $out[($row['group']['name'] ?? '-').'|'.$row['item']['code']] = [$row['issued_qty'], $row['returned_qty'], $row['net_qty'], $row['net_value']['amount_minor'] ?? null];
        }

        ksort($out);

        return $out;
    }

    public function test_consumption_by_department_cost_centre_item_and_month_nets_returns(): void
    {
        $first = $this->consume('2026-09-30 22:30:00', [['item_id' => $this->soap->id, 'qty' => '10'], ['item_id' => $this->sugar->id, 'qty' => '2.5']], null, $this->operations->id);
        $this->consume('2026-10-05 08:00:00', [['item_id' => $this->soap->id, 'qty' => '4']], $this->housekeeping);
        $this->consume('2026-10-06 08:00:00', [['item_id' => $this->soap->id, 'qty' => '3']], $this->kitchen, null, $this->storeB);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00'));
        $this->postJson("/api/v1/stores/issues/{$first['id']}/returns", ['reason' => 'unused', 'lines' => [['issue_line_id' => $first['lines'][0]['id'], 'qty' => '2']]], $this->bearer($this->tokenFor($this->owner)))->assertCreated();
        $this->travelBack();

        $this->assertSame([
            'Housekeeping|SOAP' => ['4', '0', '4', null],
            'Kitchen|SOAP' => ['13', '2', '11', null],
            'Kitchen|SUGAR' => ['2.5', '0', '2.5', null],
        ], $this->report('group=department&sort=group'));
        $this->assertSame([
            '-|SOAP' => ['7', '0', '7', null],
            'Operations|SOAP' => ['10', '2', '8', null],
            'Operations|SUGAR' => ['2.5', '0', '2.5', null],
        ], $this->report('group=cost_centre&sort=group'));
        $this->assertSame(['-|SOAP' => ['17', '2', '15', null], '-|SUGAR' => ['2.5', '0', '2.5', null]], $this->report('group=item'));

        // 22:30 UTC on 30 September is 1 October in Nairobi; the return is November's.
        $this->assertSame(['2026-10|SOAP' => ['17', '0', '17', null], '2026-11|SOAP' => ['0', '2', '-2', null]], $this->report('group=period&item='.$this->soap->id));

        // Filters: a store, a department, a period.
        $this->assertSame(['Kitchen|SOAP' => ['3', '0', '3', null]], $this->report('location='.$this->storeB->id));
        $this->assertSame(['Housekeeping|SOAP' => ['4', '0', '4', null]], $this->report('department='.$this->housekeeping->id));
        $this->assertSame(['Kitchen|SOAP' => ['10', '0', '10', null], 'Kitchen|SUGAR' => ['2.5', '0', '2.5', null]], $this->report('from=2026-10-01&to=2026-10-01'));

        $csv = $this->get('/api/v1/stores/consumption?group=department&format=csv', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('Housekeeping', $csv);
    }

    public function test_readers_see_only_their_stores_and_values_only_with_the_permission(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        $this->postJson('/api/v1/inventory/opening-stock', ['item_id' => $this->soap->id, 'location_id' => $this->store->id, 'qty' => '50', 'unit_cost' => '2'], $this->headersFor())->assertCreated();
        $this->postJson('/api/v1/inventory/opening-stock', ['item_id' => $this->soap->id, 'location_id' => $this->storeB->id, 'qty' => '50', 'unit_cost' => '2'], $this->headersFor())->assertCreated();
        $this->consume('2026-10-05 08:00:00', [['item_id' => $this->soap->id, 'qty' => '4']]);
        $this->consume('2026-10-06 08:00:00', [['item_id' => $this->soap->id, 'qty' => '3']], null, null, $this->storeB);

        // The owner sees both stores, valued (KES 2.00 a unit).
        $this->assertSame(['Kitchen|SOAP' => ['7', '0', '7', '1400']], $this->report('group=department'));
        $this->getJson('/api/v1/stores/consumption', $this->headersFor())->assertJsonPath('meta.totals', [['amount_minor' => '1400', 'currency' => 'KES']]);

        // An accountant of branch B sees store B only, with its value; a storekeeper of the main store sees quantities only.
        $accountant = $this->userWith('accountant', Scope::branch($this->branchB->id));
        $this->assertSame(['Kitchen|SOAP' => ['3', '0', '3', '600']], $this->report('group=department', $accountant));
        $this->getJson('/api/v1/stores/consumption', $this->headersFor($accountant))->assertJsonPath('meta.totals', [['amount_minor' => '600', 'currency' => 'KES']]);
        $this->assertSame(['Kitchen|SOAP' => ['4', '0', '4', null]], $this->report('group=department', $this->storekeeper));
        $this->getJson('/api/v1/stores/consumption', $this->headersFor($this->storekeeper))->assertJsonPath('meta.totals', []);
        $this->assertStringNotContainsString('Value consumed', $this->get('/api/v1/stores/consumption?format=csv', $this->headersFor($this->storekeeper))->assertOk()->streamedContent());

        $cashier = $this->userWith('cashier', Scope::location($this->store->id));
        $this->getJson('/api/v1/stores/consumption', $this->headersFor($cashier))->assertForbidden();
    }
}
