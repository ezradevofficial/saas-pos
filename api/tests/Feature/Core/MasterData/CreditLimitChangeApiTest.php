<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditEntry;
use App\Core\Currency\TenantCurrencies;
use App\Core\Identity\Models\User;
use App\Core\MasterData\CreditLimits\ApplyCreditLimitChange;
use App\Core\MasterData\CreditLimits\CreditLimitChange;
use App\Core\MasterData\CreditLimits\CreditLimitChanges;
use App\Core\MasterData\CreditLimits\CreditLimitChangeType;
use App\Core\MasterData\Parties\Http\Requests\UpdatePartyRequest;
use App\Core\MasterData\Parties\Party;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Listeners\SendWorkflowNotification;
use App\Core\Workflow\Models\DocumentWorkflow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * MD-01, WF-01, APR-01, APR-07, WF-10, WF-11, RBAC-04, RBAC-05, CUR-01:
 * credit limit change requests through their default flow (one Accountant
 * approval), applied to the party on approval; direct edits of the limit.
 */
class CreditLimitChangeApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private User $manager;

    private User $accountant;

    private User $accountant2;

    private string $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpOrganisation();
        $this->inTenant(function () {
            app(TenantCurrencies::class)->activate('KES');
            app(TenantCurrencies::class)->activate('USD');
        });
        $this->manager = $this->named('branch_manager', Scope::branch($this->branchA->id), 'Mary Manager');
        // Tenant-wide: the shared customer's requests are decided at the tenant (L2).
        $this->accountant = $this->named('accountant', Scope::tenant(), 'Ann Accountant');
        $this->accountant2 = $this->named('accountant', Scope::tenant(), 'Abel Accountant');
        $this->customer = $this->postJson('/api/v1/parties', [
            'kind' => 'organisation', 'name' => 'Duka Moja Ltd', 'roles' => ['customer'],
            'credit_limit' => '150000.00', 'credit_limit_currency' => 'KES',
        ], $this->headersFor())->assertCreated()->json('data.id');
    }

    private function named(string $template, Scope $scope, string $name): User
    {
        return $this->inTenant(function () use ($template, $scope, $name) {
            $user = $this->colleague($this->owner, ['name' => $name]);
            $this->assign($user, $this->roles->get($template), $scope);

            return $user;
        });
    }

    private function request(array $body = [], ?User $by = null)
    {
        return $this->postJson('/api/v1/credit-limit-changes', [
            'party_id' => $this->customer,
            'requested_limit' => ['amount_minor' => '25000000', 'currency' => 'KES'],
            'reason' => 'Bigger orders this season',
            ...$body,
        ], $this->headersFor($by ?? $this->manager));
    }

    private function approval(string $changeId): ApprovalRequest
    {
        return $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $changeId)->sole());
    }

    private function partyLimit(): ?string
    {
        return $this->inTenant(fn () => Party::query()->findOrFail($this->customer)->credit_limit_minor === null ? null : (string) Party::query()->findOrFail($this->customer)->credit_limit_minor);
    }

    public function test_a_request_is_numbered_snapshotted_and_waits_for_an_accountant(): void
    {
        $response = $this->request()->assertCreated();

        $response->assertJsonPath('data.number', 'CLC-000001')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.party.name', 'Duka Moja Ltd')
            ->assertJsonPath('data.company.id', $this->acme->id)
            ->assertJsonPath('data.current_limit', ['amount_minor' => '15000000', 'currency' => 'KES'])
            ->assertJsonPath('data.requested_limit', ['amount_minor' => '25000000', 'currency' => 'KES'])
            ->assertJsonPath('data.increase', ['amount_minor' => '10000000', 'currency' => 'KES'])
            ->assertJsonPath('data.requested_by.id', $this->manager->id)
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('meta.workflow.status', 'running')
            ->assertJsonPath('meta.workflow.current.0.node_id', 'approve');

        $approval = $this->approval($response->json('data.id'));
        $this->assertSame($approval->id, $response->json('meta.approval_id'));
        $this->assertSame('CLC-000001', $approval->document_number);
        // The title is the party's name only: no amounts, nothing in a language.
        $this->assertSame('Duka Moja Ltd', $approval->document_title);
        $this->assertSame(['amount_minor' => '25000000', 'currency' => 'KES'], $approval->amount());
        $this->assertSame('Accountant approves', $approval->node_name);
        $pending = $this->inTenant(fn () => $approval->assignments()->where('status', 'pending')->pluck('user_id')->sort()->values()->all());
        $this->assertEqualsCanonicalizing([$this->accountant->id, $this->accountant2->id], $pending);

        // The second request is numbered next; one open request per party.
        $this->request()->assertUnprocessable()->assertJsonPath('code', 'credit_limit_change_open');
        $this->assertSame('15000000', $this->partyLimit());
    }

    public function test_approval_applies_the_limit_with_party_history_and_audit(): void
    {
        $change = $this->request()->assertCreated()->json('data.id');
        $approval = $this->approval($change);

        // The inbox names the amount for this type (APR-04), not a generic total.
        $this->getJson("/api/v1/approvals/{$approval->id}", $this->headersFor($this->accountant))
            ->assertOk()->assertJsonPath('data.document.amount_label', 'Requested limit');

        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($this->accountant))->assertOk();

        $this->assertSame('25000000', $this->partyLimit());
        $shown = $this->getJson("/api/v1/credit-limit-changes/{$change}", $this->headersFor($this->manager))->assertOk();
        $shown->assertJsonPath('data.status', 'applied')
            ->assertJsonPath('data.decided_by.id', $this->accountant->id)
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('meta.workflow.outcome', 'approved')
            ->assertJsonPath('meta.approval_id', null);
        $this->assertNotNull($shown->json('data.applied_at'));

        $entry = $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.party.credit_limit_apply')->sole());
        $this->assertNull($entry->user_id);
        $this->assertSame($this->accountant->id, $entry->on_behalf_of_user_id);
        $this->assertSame('CLC-000001', $entry->after['credit_limit_change']);
        $this->assertEquals(15000000, $entry->before['credit_limit_minor']);
        $this->assertEquals(25000000, $entry->after['credit_limit_minor']);

        $history = $this->getJson("/api/v1/history/party/{$this->customer}", $this->headersFor())->assertOk()->json('data');
        $this->assertSame('core.party.credit_limit_apply', $history[0]['action']);
        $this->assertSame('CLC-000001', $history[0]['after']['credit_limit_change']);
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'core.credit_limit_change.update')->exists()));
    }

    public function test_rejection_leaves_the_party_untouched(): void
    {
        $change = $this->request()->assertCreated()->json('data.id');
        $approval = $this->approval($change);

        $this->postJson("/api/v1/approvals/{$approval->id}/reject", ['comment' => 'Too risky'], $this->headersFor($this->accountant))->assertOk();

        $this->getJson("/api/v1/credit-limit-changes/{$change}", $this->headersFor($this->manager))
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('meta.workflow.outcome', 'rejected');
        $this->assertSame('15000000', $this->partyLimit());
        // A new request can follow.
        $this->request()->assertCreated()->assertJsonPath('data.number', 'CLC-000002');
    }

    public function test_the_requester_cancels_and_others_cannot(): void
    {
        $change = $this->request()->assertCreated()->json('data.id');
        $url = "/api/v1/credit-limit-changes/{$change}/cancel";

        // A branch user at the other branch sees it (same company) but may not cancel it.
        $other = $this->named('branch_manager', Scope::branch($this->branchB->id), 'Ben');
        $this->postJson($url, ['reason' => 'No'], $this->headersFor($other))->assertForbidden();
        $this->postJson($url, [], $this->headersFor($this->manager))->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson($url, ['reason' => 'Customer withdrew'], $this->headersFor($this->manager))->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('meta.workflow.status', DocumentWorkflow::CANCELLED)
            ->assertJsonPath('meta.workflow.cancel_reason', 'Customer withdrew');
        $this->assertNotSame('pending', $this->approval($change)->status);
        $this->assertSame('15000000', $this->partyLimit());
        $this->postJson($url, ['reason' => 'Again'], $this->headersFor($this->manager))->assertUnprocessable()->assertJsonPath('code', 'credit_limit_change_not_open');
    }

    public function test_the_requester_never_approves_their_own_request(): void
    {
        $change = $this->request([], $this->accountant)->assertCreated()->json('data.id');
        $approval = $this->approval($change);

        $pending = $this->inTenant(fn () => $approval->assignments()->where('status', 'pending')->pluck('user_id')->all());
        $this->assertSame([$this->accountant2->id], $pending);
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($this->accountant))->assertForbidden();
        $this->assertSame('15000000', $this->partyLimit());
    }

    public function test_direct_edits_lower_freely_but_raises_need_set_directly(): void
    {
        $url = "/api/v1/parties/{$this->customer}";
        $accountant = $this->headersFor($this->accountant);

        $this->patchJson($url, ['credit_limit' => '200000', 'credit_limit_currency' => 'KES'], $accountant)
            ->assertUnprocessable()->assertJsonPath('code', 'credit_limit_needs_request');
        $this->patchJson($url, ['credit_limit' => null], $accountant)->assertUnprocessable()->assertJsonPath('code', 'credit_limit_needs_request');
        $this->patchJson($url, ['credit_limit' => '150000', 'credit_limit_currency' => 'USD'], $accountant)->assertUnprocessable()->assertJsonPath('code', 'credit_limit_needs_request');
        $this->assertSame('15000000', $this->partyLimit());

        $this->patchJson($url, ['credit_limit' => '100000', 'credit_limit_currency' => 'KES'], $accountant)->assertOk()
            ->assertJsonPath('data.credit_limit.amount_minor', '10000000');
        $this->patchJson($url, ['name' => 'Duka Moja Limited'], $accountant)->assertOk();

        // No limit means unlimited: a first limit lowers the risk (direct), removing one raises it.
        $ali = $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Ali', 'roles' => ['customer'], 'credit_limit' => '5000', 'credit_limit_currency' => 'KES'], $accountant)
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/parties/{$ali}", ['credit_limit' => null], $accountant)->assertUnprocessable()->assertJsonPath('code', 'credit_limit_needs_request');
        $bo = $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Bo', 'roles' => ['customer']], $this->headersFor())->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/parties/{$bo}", ['credit_limit' => '3000', 'credit_limit_currency' => 'USD'], $accountant)->assertOk()
            ->assertJsonPath('data.credit_limit', ['amount_minor' => '300000', 'currency' => 'USD']);

        // Owner and Admin set it directly.
        $admin = $this->named('admin', Scope::tenant(), 'Ada Admin');
        $this->patchJson($url, ['credit_limit' => '900000', 'credit_limit_currency' => 'KES'], $this->headersFor($admin))->assertOk();
        $this->patchJson($url, ['credit_limit' => '950000', 'credit_limit_currency' => 'KES'], $this->headersFor())->assertOk();
        $this->assertSame('95000000', $this->partyLimit());
    }

    public function test_currency_and_amount_rules(): void
    {
        $this->request(['requested_limit' => ['amount_minor' => '25000000', 'currency' => 'USD']])
            ->assertUnprocessable()->assertJsonPath('code', 'credit_limit_currency');
        $this->request(['requested_limit' => ['amount_minor' => '25000000', 'currency' => 'EUR']])
            ->assertUnprocessable()->assertJsonValidationErrors('requested_limit.currency');
        $this->request(['requested_limit' => ['amount_minor' => 2500.5, 'currency' => 'KES']])
            ->assertUnprocessable()->assertJsonValidationErrors('requested_limit.amount_minor');
        $this->request(['requested_limit' => ['amount_minor' => '250000.50', 'currency' => 'KES']])
            ->assertUnprocessable()->assertJsonValidationErrors('requested_limit.amount_minor');
        $this->request(['requested_limit' => ['amount_minor' => '15000000', 'currency' => 'KES']])
            ->assertUnprocessable()->assertJsonPath('code', 'credit_limit_unchanged');
        $this->request(['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');

        // A party without a limit takes any active currency; a decrease is a request too.
        $plain = $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Ali', 'roles' => ['customer']], $this->headersFor())->json('data.id');
        $this->request(['party_id' => $plain, 'requested_limit' => ['amount_minor' => '50000', 'currency' => 'USD']])->assertCreated()
            ->assertJsonPath('data.current_limit', null)
            ->assertJsonPath('data.increase', ['amount_minor' => '50000', 'currency' => 'USD']);
        $this->request(['requested_limit' => ['amount_minor' => '10000000', 'currency' => 'KES']])->assertCreated()
            ->assertJsonPath('data.increase', ['amount_minor' => '-5000000', 'currency' => 'KES']);
    }

    public function test_scope_other_companies_and_people_without_permission(): void
    {
        $beta = $this->inTenant(fn () => $this->company('Beta'));
        $this->putJson('/api/v1/master-data/settings', ['data_type' => 'customers', 'mode' => 'per_company', 'assign_to_company_id' => $this->acme->id, 'confirm' => true], $this->headersFor())->assertOk();
        $betaCustomer = $this->postJson('/api/v1/parties', ['kind' => 'organisation', 'name' => 'Beta Buyer', 'roles' => ['customer'], 'company_id' => $beta->id], $this->headersFor())
            ->assertCreated()->json('data.id');

        // Company A's branch manager: Beta's customer is not found; a request for Beta is refused.
        $this->request(['party_id' => $betaCustomer, 'requested_limit' => ['amount_minor' => '100', 'currency' => 'KES']])->assertNotFound();
        $this->request(['company_id' => $beta->id])->assertUnprocessable()->assertJsonValidationErrors('company_id');

        // A cashier has no request permission.
        $cashier = $this->named('cashier', Scope::location($this->locationA->id), 'Cas');
        $this->request([], $cashier)->assertForbidden();

        // The owner's request for Beta's customer is not seen by company A's manager.
        $betaChange = $this->request(['party_id' => $betaCustomer, 'requested_limit' => ['amount_minor' => '100', 'currency' => 'KES']], $this->owner)->assertCreated()->json('data.id');
        $this->getJson("/api/v1/credit-limit-changes/{$betaChange}", $this->headersFor($this->manager))->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/credit-limit-changes', $this->headersFor($this->manager))->assertOk()->json('data'));
    }

    public function test_a_shared_party_needs_a_company_when_the_user_reaches_several(): void
    {
        $beta = $this->inTenant(fn () => $this->company('Beta'));
        $this->request([], $this->owner)->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->request(['company_id' => $beta->id], $this->owner)->assertCreated()->assertJsonPath('data.company.id', $beta->id);
    }

    public function test_list_filters_export_and_other_tenants(): void
    {
        $first = $this->request()->assertCreated()->json('data.id');
        $this->postJson("/api/v1/approvals/{$this->approval($first)->id}/reject", ['comment' => 'No'], $this->headersFor($this->accountant))->assertOk();
        $second = $this->request()->assertCreated()->json('data.id');

        $list = $this->getJson('/api/v1/credit-limit-changes', $this->headersFor($this->manager))->assertOk();
        $this->assertSame([$second, $first], array_column($list->json('data'), 'id'));
        $this->assertSame([$first], array_column($this->getJson('/api/v1/credit-limit-changes?status=rejected', $this->headersFor())->json('data'), 'id'));
        $this->assertSame([$second, $first], array_column($this->getJson("/api/v1/credit-limit-changes?party={$this->customer}&search=CLC", $this->headersFor())->json('data'), 'id'));
        $this->assertSame([$first], array_column($this->getJson('/api/v1/credit-limit-changes?search=000001', $this->headersFor())->json('data'), 'id'));

        $csv = $this->get('/api/v1/credit-limit-changes?format=csv', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('CLC-000002', $csv);
        $this->assertStringContainsString('KES 250,000.00', $csv);

        $other = $this->otherTenant();
        $this->getJson("/api/v1/credit-limit-changes/{$first}", $this->headersFor($other['user']))->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/credit-limit-changes', $this->headersFor($other['user']))->assertOk()->json('data'));
        $this->postJson("/api/v1/credit-limit-changes/{$first}/cancel", ['reason' => 'x'], $this->headersFor($other['user']))->assertNotFound();
    }

    public function test_hidden_credit_limits_hide_amounts_and_refuse_requests(): void
    {
        $change = $this->request()->assertCreated()->json('data.id');
        $user = $this->inTenant(function () {
            $role = $this->role('Limitless', ['core.party.view', 'core.credit_limit.request']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'party', 'field' => 'credit_limit_minor', 'mode' => 'hidden']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::company($this->acme->id));

            return $user;
        });

        $shown = $this->getJson("/api/v1/credit-limit-changes/{$change}", $this->headersFor($user))->assertOk()->json('data');
        $this->assertArrayNotHasKey('current_limit', $shown);
        $this->assertArrayNotHasKey('requested_limit', $shown);
        $this->assertArrayNotHasKey('increase', $shown);
        $this->getJson('/api/v1/credit-limit-changes?sort=number', $this->headersFor($user))->assertOk();
        $this->postJson('/api/v1/credit-limit-changes', [
            'party_id' => $this->customer, 'requested_limit' => ['amount_minor' => '1', 'currency' => 'KES'], 'reason' => 'x',
        ], $this->headersFor($user))->assertForbidden();
    }

    public function test_the_apply_job_is_queued_after_approval_with_retries_and_is_idempotent(): void
    {
        $change = $this->request()->assertCreated()->json('data.id');
        Queue::fake();
        $this->postJson("/api/v1/approvals/{$this->approval($change)->id}/approve", [], $this->headersFor($this->accountant))->assertOk();

        // Decided: approved, not applied until the job runs.
        $this->getJson("/api/v1/credit-limit-changes/{$change}", $this->headersFor())->assertJsonPath('data.status', 'approved');
        $this->assertSame('15000000', $this->partyLimit());
        Queue::assertPushed(ApplyCreditLimitChange::class, fn (ApplyCreditLimitChange $job) => $job->changeId === $change && $job->tries === 3 && $job->backoff === [10, 60]);

        $tenantId = $this->owner->tenant_id;
        (new ApplyCreditLimitChange($tenantId, $change))->handle(app(CreditLimitChanges::class));
        $this->inTenant(fn () => $this->assertFalse(app(CreditLimitChanges::class)->apply($change)));
        (new ApplyCreditLimitChange($tenantId, $change))->handle(app(CreditLimitChanges::class));

        $this->assertSame('25000000', $this->partyLimit());
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.party.credit_limit_apply')->count()));
        $this->assertSame('applied', $this->inTenant(fn () => CreditLimitChange::query()->findOrFail($change)->status));
    }

    public function test_a_failed_apply_notifies_set_directly_holders_who_apply_it_again(): void
    {
        $change = $this->request()->assertCreated()->json('data.id');
        $admin = $this->named('admin', Scope::company($this->acme->id), 'Ada Admin');
        Queue::fake();
        $this->postJson("/api/v1/approvals/{$this->approval($change)->id}/approve", [], $this->headersFor($this->accountant))->assertOk();

        // The job gave up (say, the database was away): approved, not applied.
        (new ApplyCreditLimitChange($this->owner->tenant_id, $change))->failed(new \RuntimeException('down'));
        $this->getJson("/api/v1/credit-limit-changes/{$change}", $this->headersFor($admin))
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.can_apply', true);
        $notified = $this->inTenant(fn () => InAppNotification::query()->where('event_type', ApplyCreditLimitChange::FAILED_EVENT)->pluck('user_id')->all());
        $this->assertEqualsCanonicalizing([$this->owner->id, $admin->id], $notified);

        $url = "/api/v1/credit-limit-changes/{$change}/apply";
        $this->postJson($url, [], $this->headersFor($this->accountant))->assertForbidden();
        $this->postJson($url, [], $this->headersFor($admin))->assertOk()->assertJsonPath('data.status', 'applied')->assertJsonPath('data.can_apply', false);
        $this->assertSame('25000000', $this->partyLimit());
        $this->postJson($url, [], $this->headersFor($admin))->assertUnprocessable()->assertJsonPath('code', 'credit_limit_change_not_approved');
    }

    public function test_a_limit_changed_since_the_request_is_not_overwritten_and_the_request_is_conflicted(): void
    {
        $change = $this->request()->assertCreated()->json('data.id');
        // Meanwhile the owner lowers the limit directly.
        $this->patchJson("/api/v1/parties/{$this->customer}", ['credit_limit' => '120000', 'credit_limit_currency' => 'KES'], $this->headersFor())->assertOk();

        $this->postJson("/api/v1/approvals/{$this->approval($change)->id}/approve", [], $this->headersFor($this->accountant))->assertOk();

        $this->assertSame('12000000', $this->partyLimit());
        $this->getJson("/api/v1/credit-limit-changes/{$change}", $this->headersFor())
            ->assertJsonPath('data.status', 'conflicted')->assertJsonPath('data.can_apply', false);
        $this->assertSame(0, $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.party.credit_limit_apply')->count()));
        $entry = $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.credit_limit_change.conflict')->sole());
        $this->assertSame('limit_changed', $entry->after['reason']);
        $notified = $this->inTenant(fn () => InAppNotification::query()->where('event_type', CreditLimitChanges::CONFLICT_EVENT)->pluck('user_id')->all());
        $this->assertEqualsCanonicalizing([$this->manager->id, $this->accountant->id], $notified);
        // A new request can follow.
        $this->request(['requested_limit' => ['amount_minor' => '25000000', 'currency' => 'KES']])->assertCreated();
    }

    public function test_an_archived_party_is_never_changed(): void
    {
        $change = $this->request()->assertCreated()->json('data.id');
        $this->postJson("/api/v1/parties/{$this->customer}/archive", [], $this->headersFor())->assertOk();

        $this->postJson("/api/v1/approvals/{$this->approval($change)->id}/approve", [], $this->headersFor($this->accountant))->assertOk();

        $this->assertSame('15000000', $this->partyLimit());
        $this->assertSame('party_archived', $this->inTenant(fn () => CreditLimitChange::query()->findOrFail($change)->conflict_reason));
        $this->assertSame('conflicted', $this->inTenant(fn () => CreditLimitChange::query()->findOrFail($change)->status));
    }

    public function test_a_direct_edit_is_checked_again_against_the_locked_row(): void
    {
        // Validation sees KES 150,000 and allows lowering to KES 120,000; before the
        // transaction someone lowers the party to KES 100,000, so 120,000 is now a raise.
        $this->app->afterResolving(UpdatePartyRequest::class, function () {
            $this->inTenant(fn () => Party::query()->whereKey($this->customer)->firstOrFail()->forceFill(['credit_limit_minor' => 10000000])->saveQuietly());
        });

        $this->patchJson("/api/v1/parties/{$this->customer}", ['credit_limit' => '120000', 'credit_limit_currency' => 'KES'], $this->headersFor($this->accountant))
            ->assertUnprocessable()->assertJsonPath('code', 'credit_limit_needs_request');
        $this->assertSame('10000000', $this->partyLimit());
    }

    public function test_set_directly_is_tenant_wide_for_shared_parties_and_checked_at_both_companies_on_a_move(): void
    {
        // The customer is shared: an Admin of one company may not raise its limit.
        $companyAdmin = $this->named('admin', Scope::company($this->acme->id), 'Cora Company Admin');
        $this->patchJson("/api/v1/parties/{$this->customer}", ['credit_limit' => '200000', 'credit_limit_currency' => 'KES'], $this->headersFor($companyAdmin))
            ->assertUnprocessable()->assertJsonPath('code', 'credit_limit_needs_request');

        // Customers kept per company: Acme's customer, moved to Beta while raising.
        $beta = $this->inTenant(fn () => $this->company('Beta'));
        $this->putJson('/api/v1/master-data/settings', ['data_type' => 'customers', 'mode' => 'per_company', 'assign_to_company_id' => $this->acme->id, 'confirm' => true], $this->headersFor())->assertOk();
        $this->inTenant(fn () => $this->assign($companyAdmin, $this->roles->get('accountant'), Scope::company($beta->id)));
        $url = "/api/v1/parties/{$this->customer}";

        $this->patchJson($url, ['credit_limit' => '200000', 'credit_limit_currency' => 'KES', 'company_id' => $beta->id], $this->headersFor($companyAdmin))
            ->assertUnprocessable()->assertJsonPath('code', 'credit_limit_needs_request');
        $this->patchJson($url, ['credit_limit' => '200000', 'credit_limit_currency' => 'KES'], $this->headersFor($companyAdmin))->assertOk();
        $this->assertSame('20000000', $this->partyLimit());
    }

    public function test_approvers_whose_field_rules_hide_the_limit_see_no_amount_in_the_inbox_notices_or_search(): void
    {
        $this->inTenant(function () {
            FieldRule::create(['role_id' => $this->roles->get('accountant')->id, 'resource' => 'party', 'field' => 'credit_limit_minor', 'mode' => 'hidden']);
            FieldRule::create(['role_id' => $this->roles->get('accountant')->id, 'resource' => 'party', 'field' => 'name', 'mode' => 'hidden']);
        });
        $change = $this->request()->assertCreated()->json('data.id');
        $approval = $this->approval($change);

        $item = $this->getJson("/api/v1/approvals/{$approval->id}", $this->headersFor($this->accountant))->assertOk()->json('data');
        $this->assertNull($item['document']['amount']);
        $this->assertNull($item['document']['title']);
        $this->assertSame('CLC-000001', $item['document']['number']);
        $this->assertSame([], $this->getJson('/api/v1/approvals?search=Duka', $this->headersFor($this->accountant))->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/approvals?search=CLC', $this->headersFor($this->accountant))->json('data'));

        $notice = $this->inTenant(fn () => InAppNotification::query()->where('user_id', $this->accountant->id)->where('event_type', 'core.approval.requested')->sole());
        $this->assertStringNotContainsString('250,000', $notice->body);
        $this->assertStringNotContainsString('Duka', $notice->body);

        // The owner, who sees everything, still gets the amount in the oversight view.
        $all = $this->getJson('/api/v1/approvals?view=all&status=all', $this->headersFor())->assertOk()->json('data.0.document');
        $this->assertSame(['amount_minor' => '25000000', 'currency' => 'KES'], $all['amount']);
        $this->assertSame('Duka Moja Ltd', $all['title']);
    }

    public function test_a_flow_cannot_reach_approved_without_an_approval(): void
    {
        $workflow = $this->postJson('/api/v1/workflows', ['document_type' => 'core.credit_limit_change', 'company_id' => null], $this->headersFor())->assertCreated()->json('data.id');
        $this->putJson("/api/v1/workflows/{$workflow}/draft", ['graph' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'review', 'type' => 'stage', 'name' => 'Review'],
                ['id' => 'done', 'type' => 'end', 'outcome' => 'approved', 'name' => 'Done'],
            ],
            'edges' => [['from' => 'start', 'to' => 'review'], ['from' => 'review', 'to' => 'done']],
        ]], $this->headersFor())->assertOk();

        $response = $this->postJson("/api/v1/workflows/{$workflow}/publish", [], $this->headersFor())->assertUnprocessable();
        $this->assertStringContainsString('approval_required', $response->getContent());
    }

    public function test_the_flow_is_acted_on_with_the_approve_permission_and_company_colleagues_cancel(): void
    {
        $this->assertSame('core.credit_limit.approve', app(CreditLimitChangeType::class)->actPermission());
        $change = $this->request()->assertCreated()->json('data.id');

        // The Accountant (approve and request at the company) cancels the branch manager's request, named in the flow.
        $this->postJson("/api/v1/credit-limit-changes/{$change}/cancel", ['reason' => 'Duplicate'], $this->headersFor($this->accountant))->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('meta.workflow.cancelled_by.id', $this->accountant->id);
    }

    public function test_a_read_only_credit_limit_cannot_be_requested_either(): void
    {
        $user = $this->inTenant(function () {
            $role = $this->role('Read-only limits', ['core.party.view', 'core.credit_limit.request']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'party', 'field' => 'credit_limit_minor', 'mode' => 'readonly']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::company($this->acme->id));

            return $user;
        });

        $this->request([], $user)->assertForbidden();
    }

    public function test_a_shared_party_is_decided_at_the_tenant_whatever_company_is_named(): void
    {
        $companyAccountant = $this->named('accountant', Scope::company($this->acme->id), 'Carl Company Accountant');
        $change = $this->request()->assertCreated()->assertJsonPath('data.company.id', $this->acme->id)->json('data.id');
        $approval = $this->approval($change);

        $this->assertNull($approval->company_id);
        $pending = $this->inTenant(fn () => $approval->assignments()->where('status', 'pending')->pluck('user_id')->all());
        $this->assertEqualsCanonicalizing([$this->accountant->id, $this->accountant2->id], $pending);
        $this->assertNotContains($companyAccountant->id, $pending);
    }

    public function test_only_the_requests_own_flow_settles_it(): void
    {
        $change = $this->request()->assertCreated()->json('data.id');

        $this->inTenant(fn () => WorkflowCompleted::dispatch($this->owner->tenant_id, '01890000-0000-7000-8000-000000000000', 'core.credit_limit_change', $change, 'approved', $this->owner->id));

        $this->assertSame('pending', $this->inTenant(fn () => CreditLimitChange::query()->findOrFail($change)->status));
        $this->assertSame('15000000', $this->partyLimit());
    }

    public function test_concurrent_requests_map_only_the_open_request_clash_and_numbers_stay_unique(): void
    {
        $other = $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Ali', 'roles' => ['customer']], $this->headersFor())->json('data.id');
        $this->request(['party_id' => $other, 'requested_limit' => ['amount_minor' => '100', 'currency' => 'KES']])->assertCreated()->assertJsonPath('data.number', 'CLC-000001');

        // Another request for the same party commits between our check and our insert.
        $armed = 'party';
        $clash = function (CreditLimitChange $change) use (&$armed) {
            if ($armed !== 'party') {
                return;
            }

            $armed = null;
            CreditLimitChange::query()->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $this->owner->tenant_id, 'seq' => 900, 'number' => 'CLC-000900',
                'party_id' => $change->party_id, 'company_id' => $change->company_id, 'requested_limit_minor' => 1, 'requested_limit_currency' => 'KES',
                'reason' => 'race', 'status' => 'pending', 'requested_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        CreditLimitChange::creating($clash);
        $this->request()->assertUnprocessable()->assertJsonPath('code', 'credit_limit_change_open');

        // A clash on the number is a fault, never "an open request exists".
        $armed = 'number';
        CreditLimitChange::creating(function (CreditLimitChange $change) use (&$armed) {
            if ($armed !== 'number') {
                return;
            }

            $armed = null;
            CreditLimitChange::query()->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $this->owner->tenant_id, 'seq' => $change->seq, 'number' => $change->number,
                'party_id' => $change->party_id === $this->customer ? $this->otherParty() : $this->customer, 'company_id' => $change->company_id,
                'requested_limit_minor' => 1, 'requested_limit_currency' => 'KES', 'reason' => 'race', 'status' => 'rejected',
                'requested_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $this->withoutExceptionHandling();

        try {
            $this->request();
            $this->fail('A duplicate number must not be accepted.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('credit_limit_changes_tenant_id_seq_unique', $e->getMessage());
        }
    }

    private function otherParty(): string
    {
        return (string) Party::query()->where('id', '!=', $this->customer)->value('id');
    }

    public function test_cdf_amounts_have_no_decimals(): void
    {
        $this->inTenant(fn () => app(TenantCurrencies::class)->activate('CDF'));
        $party = $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Kin', 'roles' => ['customer'], 'credit_limit' => '135000', 'credit_limit_currency' => 'CDF'], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.credit_limit', ['amount_minor' => '135000', 'currency' => 'CDF'])->json('data.id');

        $change = $this->request(['party_id' => $party, 'requested_limit' => ['amount_minor' => '250000', 'currency' => 'CDF']])->assertCreated()
            ->assertJsonPath('data.increase', ['amount_minor' => '115000', 'currency' => 'CDF'])->json('data.id');
        $csv = $this->get('/api/v1/credit-limit-changes?format=csv', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('CDF 250,000', $csv);
        $this->assertStringNotContainsString('CDF 250,000.', $csv);

        $this->postJson("/api/v1/approvals/{$this->approval($change)->id}/approve", [], $this->headersFor($this->accountant))->assertOk();
        $this->getJson("/api/v1/parties/{$party}", $this->headersFor())->assertJsonPath('data.credit_limit', ['amount_minor' => '250000', 'currency' => 'CDF']);
    }

    public function test_its_flow_status_names_the_request_and_links_to_its_own_page(): void
    {
        $id = $this->request()->assertCreated()->json('data.id');
        $url = '/api/v1/document-workflows/'.CreditLimitChangeType::KEY.'/'.$id;

        // WF-10: number and title from the summary; the type's own page (LinksDocuments).
        $this->getJson($url, $this->headersFor())->assertOk()
            ->assertJsonPath('data.document.number', 'CLC-000001')
            ->assertJsonPath('data.document.title', 'Duka Moja Ltd')
            ->assertJsonPath('data.document.link', '/contacts/credit-limit-changes?change='.$id);
        // Workflow notifications and approvals link there too.
        $this->assertSame('/contacts/credit-limit-changes?change='.$id, $this->inTenant(fn () => SendWorkflowNotification::link(CreditLimitChangeType::KEY, $id)));

        // RBAC-05: a viewer whose field rules hide the party's name sees no title.
        $user = $this->inTenant(function () {
            $role = $this->role('Nameless approver', ['core.party.view']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'party', 'field' => 'name', 'mode' => 'hidden']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });
        $this->getJson($url, $this->headersFor($user))->assertOk()
            ->assertJsonPath('data.document.number', 'CLC-000001')
            ->assertJsonPath('data.document.title', null);
    }

    public function test_a_hidden_party_name_is_masked_and_never_searched(): void
    {
        $this->request()->assertCreated();
        $user = $this->inTenant(function () {
            $role = $this->role('Nameless', ['core.party.view']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'party', 'field' => 'name', 'mode' => 'hidden']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::company($this->acme->id));

            return $user;
        });

        $list = $this->getJson('/api/v1/credit-limit-changes', $this->headersFor($user))->assertOk()->json('data');
        $this->assertNull($list[0]['party']['name']);
        $this->assertSame([], $this->getJson('/api/v1/credit-limit-changes?search=Duka', $this->headersFor($user))->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/credit-limit-changes?search=Duka', $this->headersFor())->json('data'));
    }
}
