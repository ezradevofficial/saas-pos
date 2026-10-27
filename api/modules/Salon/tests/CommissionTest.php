<?php

namespace Modules\Salon\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Modules\Payroll\Models\EmployeeItem;
use Modules\Payroll\Models\PayrollEmployee;
use Modules\Salon\Models\CommissionEntry;
use Modules\Salon\Models\CommissionRule;
use Modules\Salon\Tests\Concerns\BuildsSalon;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// SAL-20 to SAL-24: rules, the most specific one wins, batches are reviewed by someone else, exported once, and reversals are carried forward.
class CommissionTest extends TestCase
{
    use BuildsSalon, RefreshTenantDatabase;

    private int $seq = 0;

    /** A paid haircut or colour by $staff at $time, sold at the main till; returns the sale. */
    private function sold(string $time, string $item = 'haircut', ?string $customer = null): array
    {
        $service = $item === 'colour' ? $this->colour : $this->haircut;
        $made = $this->book($time, $this->ann, [], $service);
        foreach (['check-in', 'start', 'finish'] as $step) {
            $this->api('POST', "salon/appointments/{$made['id']}/{$step}", [], $this->annUser)->assertOk();
        }
        $sale = $this->salonSale($made['id'], ++$this->seq, $this->desk);
        $this->sell($sale);

        return $sale;
    }

    private function build(array $overrides = [], $user = null)
    {
        return $this->api('POST', 'salon/commission-batches', ['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'period_start' => '2027-03-01', 'period_end' => '2027-03-31', 'currency' => 'KES', ...$overrides], $user ?? $this->manager);
    }

    public function test_the_most_specific_live_rule_wins_and_no_rule_earns_nothing(): void
    {
        $this->setUpSalon(rules: false);
        $this->sold('09:00');
        $this->assertSame(0, $this->inTenant(fn () => CommissionEntry::query()->count()), 'with no rule nobody earns');

        $this->inTenant(function () {
            // Everyone 10 % of services; Ann 15 %; Ann on colour a fixed KES 500.00; an old rule and a future rule do not count.
            $rule = fn (array $o) => CommissionRule::create(['company_id' => $this->acme->id, 'applies_to' => 'service', 'kind' => 'percent', 'effective_from' => '2026-01-01', ...$o]);
            $rule(['rate' => '10']);
            $rule(['staff_id' => $this->ann->id, 'rate' => '15']);
            $rule(['staff_id' => $this->ann->id, 'service_id' => $this->colour->id, 'kind' => 'fixed', 'rate' => null, 'amount_minor' => 50000, 'currency' => 'KES']);
            $rule(['staff_id' => $this->ann->id, 'rate' => '99', 'effective_from' => '2025-01-01', 'effective_to' => '2025-12-31']);
            $rule(['staff_id' => $this->ann->id, 'rate' => '98', 'effective_from' => '2027-04-01']);
        });

        $this->sold('10:00');                       // haircut by Ann: 15 % of 88,889
        $this->sold('11:00', 'colour');             // colour by Ann: the fixed KES 500.00
        $amounts = $this->inTenant(fn () => CommissionEntry::query()->orderBy('earned_at')->orderBy('amount_minor')->pluck('amount_minor')->all());
        $this->assertSame([13333, 50000], $amounts);
        // The rule that applied is kept with the entry.
        $this->assertSame(['fixed', 'percent'], $this->inTenant(fn () => CommissionEntry::query()->get()->map(fn ($e) => $e->rule_snapshot['kind'])->sort()->values()->all()));
    }

    public function test_rules_are_set_up_by_the_admin_only_and_name_things_of_the_company(): void
    {
        $this->setUpSalon();
        $body = ['company_id' => $this->acme->id, 'staff_id' => $this->bob->id, 'applies_to' => 'service', 'kind' => 'percent', 'rate' => '12.5', 'effective_from' => '2027-03-01'];
        $this->api('POST', 'salon/commission-rules', $body, $this->manager)->assertForbidden();
        $made = $this->api('POST', 'salon/commission-rules', $body, $this->owner)->assertCreated()->assertJsonPath('data.rate', '12.5')->json('data');
        $this->api('POST', 'salon/commission-rules', [...$body, 'staff_id' => $this->id()], $this->owner)->assertStatus(422);
        $this->api('POST', 'salon/commission-rules', [...$body, 'rate' => '101'], $this->owner)->assertStatus(422);
        $this->api('POST', 'salon/commission-rules', [...$body, 'kind' => 'fixed'], $this->owner)->assertStatus(422);
        $this->api('POST', 'salon/commission-rules', [...$body, 'applies_to' => 'product', 'service_id' => $this->haircut->id], $this->owner)->assertStatus(422)->assertJsonPath('code', 'rule_product_service');
        $this->api('PUT', "salon/commission-rules/{$made['id']}", ['rate' => '20'], $this->owner)->assertOk()->assertJsonPath('data.rate', '20');
        $this->api('GET', 'salon/commission-rules?branch_id='.$this->branchA->id, [], $this->manager)->assertOk()->assertJsonCount(3, 'data');
        $this->api('POST', "salon/commission-rules/{$made['id']}/archive", [], $this->owner)->assertOk();
        $this->api('GET', 'salon/commission-rules?branch_id='.$this->branchA->id, [], $this->manager)->assertOk()->assertJsonCount(2, 'data');
        // A stylist reads only their own earnings.
        $this->api('GET', 'salon/commission-rules?branch_id='.$this->branchA->id, [], $this->annUser)->assertForbidden();
    }

    public function test_a_batch_is_built_reviewed_by_someone_else_approved_and_exported_once_as_a_report(): void
    {
        $this->setUpSalon();
        $this->sold('09:00');
        $this->sold('10:00');

        $batch = $this->build()->assertCreated()->json('data');
        $this->assertSame(['draft', '35556', 1], [$batch['status'], $batch['total_minor'], $batch['staff_count']]);
        $this->assertSame('35556', $batch['payouts'][0]['amount_minor']);
        $this->build()->assertStatus(422)->assertJsonPath('code', 'nothing_to_batch');
        $id = $batch['id'];

        // Not approved before it is submitted; the builder never decides it (nor does the submitter), and neither does a stylist.
        $this->api('POST', "salon/commission-batches/{$id}/approve", [], $this->branchManager)->assertStatus(409)->assertJsonPath('code', 'batch_status');
        $this->api('POST', "salon/commission-batches/{$id}/submit", [], $this->manager)->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->api('POST', "salon/commission-batches/{$id}/approve", [], $this->manager)->assertStatus(403)->assertJsonPath('code', 'self_approval');
        $this->api('POST', "salon/commission-batches/{$id}/reject", ['reason' => 'Mine'], $this->manager)->assertStatus(403)->assertJsonPath('code', 'self_approval');
        $this->api('POST', "salon/commission-batches/{$id}/approve", [], $this->annUser)->assertForbidden();

        // Someone paid in the batch cannot approve it, even holding the right to: Ann given the manager role as well.
        $this->inTenant(fn () => $this->assign($this->annUser, $this->roles->get('salon_manager'), Scope::branch($this->branchA->id)));
        $this->api('POST', "salon/commission-batches/{$id}/approve", [], $this->annUser)->assertStatus(403)->assertJsonPath('code', 'self_approval');
        $this->assertSame(0, AuditEntry::where('action', 'salon.commission_batch.approve')->count());

        // Another manager approves; an approved batch cannot be approved or rejected again.
        $approved = $this->api('POST', "salon/commission-batches/{$id}/approve", [], $this->branchManager)->assertOk()->json('data');
        $this->assertSame(['approved', $this->branchManager->name], [$approved['status'], $approved['decided_by_name']]);
        $this->api('POST', "salon/commission-batches/{$id}/approve", [], $this->branchManager)->assertStatus(409);

        // Export needs its own permission and happens once; without Payroll the batch is the report.
        $this->api('POST', "salon/commission-batches/{$id}/export", [], $this->desk)->assertForbidden();
        $exported = $this->api('POST', "salon/commission-batches/{$id}/export", [], $this->manager)->assertOk()->json();
        $this->assertSame(['exported', 'report', 'reported', []], [$exported['data']['status'], $exported['data']['export_mode'], $exported['data']['payouts'][0]['status'], $exported['refused']]);
        $this->api('POST', "salon/commission-batches/{$id}/export", [], $this->manager)->assertStatus(409)->assertJsonPath('code', 'batch_exported');
        $csv = $this->api('GET', "salon/commission-batches/{$id}/csv", [], $this->manager)->assertOk()->getContent();
        $this->assertStringContainsString('Ann,,KES,35556,2,reported', $csv);
        $counts = AuditEntry::where('action', 'like', 'salon.commission_batch.%')->get()->countBy('action');

        foreach (['build', 'submit', 'approve', 'export'] as $verb) {
            $this->assertSame(1, $counts["salon.commission_batch.{$verb}"] ?? 0, "one {$verb} is audited");
        }
    }

    public function test_a_rejected_batch_gives_its_entries_back(): void
    {
        $this->setUpSalon();
        $this->sold('09:00');
        $id = $this->build()->assertCreated()->json('data.id');
        $this->api('POST', "salon/commission-batches/{$id}/submit", [], $this->manager)->assertOk();
        $this->api('POST', "salon/commission-batches/{$id}/reject", [], $this->branchManager)->assertStatus(422);
        $this->api('POST', "salon/commission-batches/{$id}/reject", ['reason' => 'Check the period'], $this->branchManager)->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(0, $this->inTenant(fn () => CommissionEntry::query()->whereNotNull('batch_id')->count()));
        $this->build()->assertCreated();
    }

    public function test_a_reversal_after_the_export_is_carried_into_the_next_batch(): void
    {
        $this->setUpSalon();
        $first = $this->sold('09:00');
        $id = $this->build()->assertCreated()->json('data.id');
        $this->api('POST', "salon/commission-batches/{$id}/submit", [], $this->manager)->assertOk();
        $this->api('POST', "salon/commission-batches/{$id}/approve", [], $this->branchManager)->assertOk();
        $this->api('POST', "salon/commission-batches/{$id}/export", [], $this->manager)->assertOk();

        // The first sale is voided after it was paid out: a negative entry, nothing to pay, carried forward.
        $this->voidSale($first);
        $this->build()->assertStatus(422)->assertJsonPath('code', 'nothing_to_batch');
        $this->assertSame(-17778, (int) $this->inTenant(fn () => CommissionEntry::query()->whereNull('batch_id')->sum('amount_minor')));

        // A colour earns 20 % of 266,667 (53,333); the next batch pays it less what was taken back.
        $this->sold('11:00', 'colour');
        $second = $this->build()->assertCreated()->json('data');
        $this->assertSame(['35555', 2], [$second['payouts'][0]['amount_minor'], $second['payouts'][0]['entry_count']]);
    }

    public function test_commissions_are_read_by_those_who_may_and_a_stylist_sees_only_their_own(): void
    {
        $this->setUpSalon();
        $this->inTenant(fn () => CommissionRule::create(['company_id' => $this->acme->id, 'staff_id' => $this->bob->id, 'applies_to' => 'service', 'kind' => 'percent', 'rate' => '10', 'effective_from' => '2026-01-01']));
        $this->sold('09:00');
        $made = $this->book('11:00', $this->bob);
        foreach (['check-in', 'start', 'finish'] as $step) {
            $this->api('POST', "salon/appointments/{$made['id']}/{$step}", [], $this->bobUser)->assertOk();
        }
        $this->sell($this->salonSale($made['id'], 9, $this->desk));

        $all = $this->api('GET', 'salon/commissions?branch_id='.$this->branchA->id, [], $this->manager)->assertOk()->json('data');
        $this->assertSame(['Ann', 'Bob'], collect($all)->pluck('staff_name')->sort()->values()->all());
        $mine = $this->api('GET', 'salon/commissions', [], $this->bobUser)->assertOk()->json('data');
        $this->assertSame(['Bob'], collect($mine)->pluck('staff_name')->unique()->all());
        // The receptionist has no business with anyone's pay.
        $this->api('GET', 'salon/commissions', [], $this->desk)->assertForbidden();
        $this->build([], $this->annUser)->assertForbidden();
        // A branch manager cannot build the company's batch: only the branch's.
        $this->build(['branch_id' => null])->assertForbidden();
        $this->build(['branch_id' => $this->branchB->id])->assertForbidden();
    }

    public function test_an_approved_batch_goes_to_payroll_as_one_off_earnings_once(): void
    {
        $this->setUpSalon();
        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('hr');
            app(ModuleRegistry::class)->activate('payroll');
        });
        $employee = $this->api('POST', 'hr/employees', ['company_id' => $this->acme->id, 'first_name' => 'Ann', 'last_name' => 'Stylist', 'hire_date' => '2026-01-05', 'branch_id' => $this->branchA->id], $this->owner)->assertCreated()->json('data.id');
        $this->api('PUT', "salon/staff/{$this->ann->id}", ['employee_id' => $employee], $this->owner)->assertOk()->assertJsonPath('data.employee_id', $employee);
        // One employee is one staff member.
        $this->api('PUT', "salon/staff/{$this->bob->id}", ['employee_id' => $employee], $this->owner)->assertStatus(422)->assertJsonPath('code', 'employee_taken');
        $this->api('PUT', "salon/staff/{$this->bob->id}", ['employee_id' => $this->id()], $this->owner)->assertStatus(422)->assertJsonPath('code', 'employee_unknown');

        // HR is now the source of Ann's hours: with no planned shift she cannot be booked; with one (08:00 to 18:00) she can.
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id]], '09:00'), $this->desk)->assertStatus(422)->assertJsonPath('code', 'outside_hours');
        $this->inTenant(function () use ($employee) {
            $shift = \Modules\HR\Models\Shift::create(['company_id' => $this->acme->id, 'code' => 'DAY', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '18:00', 'break_minutes' => 0]);
            \Modules\HR\Models\RosterShift::create([
                'employee_id' => $employee, 'company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'date' => $this->today, 'shift_id' => $shift->id,
                'start_at' => $this->at('08:00'), 'end_at' => $this->at('18:00'), 'break_minutes' => 0, 'source' => 'manual', 'status' => 'planned',
            ]);
        });
        $this->sold('09:00');
        $id = $this->build()->assertCreated()->json('data.id');
        $this->api('POST', "salon/commission-batches/{$id}/submit", [], $this->manager)->assertOk();
        $this->api('POST', "salon/commission-batches/{$id}/approve", [], $this->branchManager)->assertOk();

        // Payroll has no commission earning yet: nothing is exported and the batch stays approved, with the reason.
        $refused = $this->api('POST', "salon/commission-batches/{$id}/export", [], $this->manager)->assertOk()->json();
        $this->assertSame(['approved', 'item_unknown'], [$refused['data']['status'], $refused['refused'][0]['reason']]);

        $this->api('POST', 'payroll/items', ['company_id' => $this->acme->id, 'code' => 'COMMISSION', 'name' => 'Salon commission', 'kind' => 'earning', 'taxable' => true, 'contributory' => false], $this->owner)->assertCreated();
        $exported = $this->api('POST', "salon/commission-batches/{$id}/export", [], $this->manager)->assertOk()->json('data');
        $this->assertSame(['exported', 'payroll', 'exported'], [$exported['status'], $exported['export_mode'], $exported['payouts'][0]['status']]);

        $items = $this->inTenant(fn () => EmployeeItem::query()->get());
        $this->assertCount(1, $items);
        $this->assertSame([17778, 'KES', 'once', '2027-03-31'], [$items[0]->amount_minor, $items[0]->currency, $items[0]->recurrence, $items[0]->period_date->toDateString()]);
        $this->assertSame($employee, $this->inTenant(fn () => PayrollEmployee::query()->find($items[0]->payroll_employee_id)->employee_id));
        // The same batch cannot be sent twice; the contract is idempotent by batch and staff even if it were asked again.
        $this->api('POST', "salon/commission-batches/{$id}/export", [], $this->manager)->assertStatus(409);
        $again = $this->inTenant(fn () => app(\Modules\Payroll\Contracts\ExternalEarnings::class)->addOnce($this->acme->id, $employee, 'COMMISSION', 17778, 'KES', '2027-03-31', "salon:{$id}:{$this->ann->id}", null));
        $this->assertSame('exists', $again['status']);
        $this->assertSame(1, $this->inTenant(fn () => EmployeeItem::query()->count()));
    }
}
