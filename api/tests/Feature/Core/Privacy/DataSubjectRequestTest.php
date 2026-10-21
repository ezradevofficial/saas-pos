<?php

namespace Tests\Feature\Core\Privacy;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Privacy\Models\PrivacyRequest;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-08: data subject requests (access, correction, deletion by
 * anonymisation) with a legal deadline, four-eyes approval and audit;
 * permission-denied and other-tenant cases (TEN-01, RBAC-04).
 */
class DataSubjectRequestTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private User $admin;

    private Party $amina;

    private Party $baraka;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganisation();
        $this->admin = $this->userWith('admin', Scope::tenant());

        $this->inTenant(function () {
            $this->acme->forceFill(['country' => 'KE'])->save();
            $this->amina = Party::query()->create([
                'company_id' => $this->acme->id, 'kind' => 'person', 'name' => 'Amina Otieno', 'tax_id' => 'A012345678Z',
                'phones' => [['number' => '+254712345678', 'label' => 'mobile']], 'emails' => [['address' => 'amina@example.com', 'label' => null]],
                'roles' => ['customer'],
            ]);
            $this->baraka = Party::query()->create([
                'company_id' => $this->acme->id, 'kind' => 'person', 'name' => 'Baraka Mwangi',
                'emails' => [['address' => 'baraka@example.com', 'label' => null]], 'roles' => ['customer'],
            ]);
            // Related records: a loyalty account each, and a CRM lead converted to Amina.
            foreach ([$this->amina, $this->baraka] as $party) {
                DB::table('loyalty_accounts')->insert([
                    'id' => (string) Str::uuid7(), 'company_id' => $this->acme->id, 'party_id' => $party->id,
                    'kind' => 'points', 'status' => 'open', 'opened_at' => now(),
                ]);
            }
            DB::table('crm_leads')->insert([
                'id' => (string) Str::uuid7(), 'company_id' => $this->acme->id, 'branch_id' => $this->branchA->id,
                'status' => 'converted', 'channel' => 'manual', 'name' => 'Amina O.', 'email' => 'amina@example.com',
                'phone' => '+254712345678', 'party_id' => $this->amina->id,
            ]);
        });
    }

    /** @param array<string, mixed> $body */
    private function create(string $kind, array $body = [], ?User $as = null): string
    {
        return $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'customer', 'subject_id' => $this->amina->id, 'kind' => $kind, ...$body], $this->headersFor($as))
            ->assertCreated()->json('data.id');
    }

    /** Logged by the owner, approved by the admin, carried out by the owner. */
    private function completed(string $kind, array $body = []): string
    {
        $id = $this->create($kind, $body);
        $this->postJson("/api/v1/privacy/requests/{$id}/approve", [], $this->headersFor($this->admin))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/privacy/requests/{$id}/execute", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'completed');

        return $id;
    }

    public function test_a_request_gets_its_deadline_from_the_subjects_country(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now()->startOfSecond());
        config(['privacy.deadline_days.KE' => 7]);

        $response = $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'customer', 'subject_id' => $this->amina->id, 'kind' => 'access', 'note' => 'By phone'], $this->headersFor())
            ->assertCreated()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.subject_name', 'Amina Otieno')
            ->assertJsonPath('data.overdue', false);

        $this->assertSame($now->addDays(7)->toIso8601String(), $response->json('data.due_at'));

        // Past its deadline and still open: flagged overdue.
        $this->inTenant(fn () => PrivacyRequest::query()->whereKey($response->json('data.id'))->update(['due_at' => CarbonImmutable::now()->subDay()]));
        $this->getJson('/api/v1/privacy/requests?status=open', $this->headersFor())->assertOk()->assertJsonPath('data.0.overdue', true);
        CarbonImmutable::setTestNow();
    }

    public function test_access_reports_the_subjects_data_without_secrets_or_other_people(): void
    {
        $id = $this->completed('access');

        $report = $this->get("/api/v1/privacy/requests/{$id}/report", $this->headersFor())->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="access-report-'.$id.'.json"')
            ->json();

        $this->assertSame(['type' => 'customer', 'id' => $this->amina->id], $report['subject']);
        $this->assertSame('Amina Otieno', $report['records']['parties'][0]['name']);
        $this->assertCount(1, $report['records']['parties']);
        $this->assertSame('Amina O.', $report['records']['crm_leads'][0]['name']);
        $this->assertSame(1, $report['related']['loyalty_accounts']['count']);
        $this->assertSame($this->amina->id, $report['related']['loyalty_accounts']['rows'][0]['party_id']);
        $this->assertStringNotContainsString('Baraka', json_encode($report));
        $this->assertStringNotContainsString($this->baraka->id, json_encode($report));

        $this->inTenant(fn () => $this->assertTrue(AuditEntry::query()->where('action', 'core.privacy_request.download')->exists()));
    }

    public function test_the_report_is_only_for_a_completed_access_request(): void
    {
        $id = $this->create('access');

        $this->getJson("/api/v1/privacy/requests/{$id}/report", $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'report_unavailable');
    }

    public function test_correction_applies_the_approved_values_and_audits_them(): void
    {
        $id = $this->completed('correction', ['corrections' => ['name' => 'Amina Achieng Otieno', 'emails' => [['address' => 'amina.otieno@example.com']]]]);

        $this->inTenant(function () use ($id) {
            $party = Party::query()->findOrFail($this->amina->id);
            $this->assertSame('Amina Achieng Otieno', $party->name);
            $this->assertSame('amina.otieno@example.com', $party->emails[0]['address']);
            $entry = AuditEntry::query()->where('action', 'core.privacy_request.execute')->where('auditable_id', $id)->firstOrFail();
            $this->assertSame('Amina Achieng Otieno', $entry->after['name']);
        });
    }

    public function test_a_correction_may_change_only_correctable_fields(): void
    {
        $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'customer', 'subject_id' => $this->amina->id, 'kind' => 'correction', 'corrections' => ['credit_limit_minor' => 1]], $this->headersFor())
            ->assertStatus(422)->assertJsonValidationErrors(['corrections.credit_limit_minor']);
        $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'customer', 'subject_id' => $this->amina->id, 'kind' => 'correction'], $this->headersFor())
            ->assertStatus(422)->assertJsonValidationErrors(['corrections']);
    }

    public function test_deletion_anonymises_personal_data_and_keeps_business_records(): void
    {
        $id = $this->completed('deletion');

        $this->inTenant(function () use ($id) {
            $party = DB::table('parties')->where('id', $this->amina->id)->first();
            $this->assertStringStartsWith('Anonymised ', $party->name);
            $this->assertNull($party->tax_id);
            $this->assertSame('[]', $party->phones);
            $this->assertSame('[]', $party->emails);
            $this->assertNotNull($party->archived_at);

            $lead = DB::table('crm_leads')->where('party_id', $this->amina->id)->first();
            $this->assertStringStartsWith('Anonymised ', $lead->name);
            $this->assertNull($lead->email);
            $this->assertNull($lead->phone);

            // Business records are kept (TEN-06), still linked to the anonymised party.
            $this->assertSame(1, DB::table('loyalty_accounts')->where('party_id', $this->amina->id)->count());
            // Another customer is untouched.
            $this->assertSame('Baraka Mwangi', DB::table('parties')->where('id', $this->baraka->id)->value('name'));

            $request = PrivacyRequest::query()->findOrFail($id);
            $this->assertSame(['name', 'legal_name', 'tax_id', 'phones', 'emails', 'addresses', 'custom'], $request->result['anonymised']['parties']);
            $this->assertContains('loyalty_accounts', $request->result['retained']);

            // The audit entry names fields, never the old values.
            $entry = AuditEntry::query()->where('action', 'core.privacy_request.execute')->where('auditable_id', $id)->firstOrFail();
            $this->assertStringNotContainsString('amina@example.com', json_encode([$entry->before, $entry->after]));
            $this->assertStringNotContainsString('Amina Otieno', json_encode([$entry->before, $entry->after]));
        });
    }

    public function test_deleting_a_user_deactivates_and_signs_them_out_but_never_the_last_owner(): void
    {
        $clerk = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->headersFor($clerk);

        $id = $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'user', 'subject_id' => $clerk->id, 'kind' => 'deletion'], $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/privacy/requests/{$id}/approve", [], $this->headersFor($this->admin))->assertOk();
        $this->postJson("/api/v1/privacy/requests/{$id}/execute", [], $this->headersFor())->assertOk();

        $this->inTenant(function () use ($clerk) {
            $user = User::query()->findOrFail($clerk->id);
            $this->assertSame(User::STATUS_DEACTIVATED, $user->status);
            $this->assertStringEndsWith('@anonymised.invalid', (string) $user->email);
            $this->assertSame(0, $user->tokens()->count());
        });

        // Deactivation is audited as user management does it (review M4).
        $this->inTenant(fn () => $this->assertTrue(AuditEntry::query()->where('action', 'core.user.deactivate')->where('auditable_id', $clerk->id)->exists()));

        // The last Owner asks about themself; the admin approves; it is still refused.
        $last = $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'user', 'subject_id' => $this->owner->id, 'kind' => 'deletion'], $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/privacy/requests/{$last}/approve", [], $this->headersFor($this->admin))->assertOk();
        $this->postJson("/api/v1/privacy/requests/{$last}/execute", [], $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'last_owner');
        $this->inTenant(fn () => $this->assertSame('approved', PrivacyRequest::query()->findOrFail($last)->status));
    }

    /** Review M4: a user's deletion is user management: only someone who may deactivate them asks for or carries it out. */
    public function test_only_someone_who_may_deactivate_the_user_asks_for_or_carries_out_their_deletion(): void
    {
        // An Admin cannot manage an Owner (RBAC-10), so cannot ask to delete one.
        $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'user', 'subject_id' => $this->owner->id, 'kind' => 'deletion'], $this->headersFor($this->admin))
            ->assertForbidden()->assertJsonPath('code', 'deletion_not_allowed');

        // Logged by the owner about a second owner, approved by the admin: the admin cannot carry it out.
        $second = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->roles->get('owner'), Scope::tenant());

            return $user;
        });
        $id = $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'user', 'subject_id' => $second->id, 'kind' => 'deletion'], $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/privacy/requests/{$id}/approve", [], $this->headersFor($this->admin))->assertOk();
        $this->postJson("/api/v1/privacy/requests/{$id}/execute", [], $this->headersFor($this->admin))->assertForbidden()->assertJsonPath('code', 'deletion_not_allowed');
        $this->inTenant(fn () => $this->assertSame(User::STATUS_ACTIVE, User::query()->findOrFail($second->id)->status));
    }

    /**
     * Review H1: a user's access report holds rows about them only: never the
     * leads they own, sales they rang up as cashier or anything else they
     * merely touched for someone else.
     */
    public function test_a_users_report_never_holds_rows_about_other_people(): void
    {
        $clerk = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->inTenant(fn () => DB::table('crm_leads')->insert([
            'id' => (string) Str::uuid7(), 'company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'status' => 'new',
            'channel' => 'manual', 'name' => 'Other Person', 'email' => 'other.person@example.com', 'owner_id' => $clerk->id,
        ]));

        $id = $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'user', 'subject_id' => $clerk->id, 'kind' => 'access'], $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/privacy/requests/{$id}/approve", [], $this->headersFor($this->admin))->assertOk();
        $this->postJson("/api/v1/privacy/requests/{$id}/execute", [], $this->headersFor())->assertOk();
        $report = $this->getJson("/api/v1/privacy/requests/{$id}/report", $this->headersFor())->assertOk()->json();

        $this->assertSame($clerk->id, $report['records']['users'][0]['id']);
        $tables = [...array_keys($report['records']), ...array_keys($report['related'])];
        $this->assertSame([], array_values(array_diff($tables, ['users', 'login_events', 'role_assignments', 'till_sign_ins', 'hr_employees'])), json_encode($tables));
        $this->assertStringNotContainsString('Other Person', json_encode($report));
        $this->assertStringNotContainsString('other.person@example.com', json_encode($report));
        $this->assertStringNotContainsString('Amina Otieno', json_encode($report));
    }

    /** Review M5: sign-in history, code destinations and the request's own free text go with a deletion. */
    public function test_a_deletion_clears_sign_in_history_code_destinations_and_the_requests_own_text(): void
    {
        $clerk = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->inTenant(function () use ($clerk) {
            DB::table('login_events')->insert(['id' => (string) Str::uuid7(), 'user_id' => $clerk->id, 'ip' => '197.248.1.1', 'user_agent' => 'Chrome on Android', 'fingerprint' => str_repeat('f', 64), 'succeeded' => true, 'created_at' => now()]);
            DB::table('verification_challenges')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $clerk->tenant_id, 'user_id' => $clerk->id, 'purpose' => 'two_factor', 'channel' => 'sms', 'destination' => '+254712000111', 'code_hash' => str_repeat('a', 64), 'attempts' => 0, 'expires_at' => now()->addMinutes(5), 'created_at' => now()]);
        });

        $id = $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'user', 'subject_id' => $clerk->id, 'kind' => 'deletion', 'note' => 'Asked by Mary Wambui on 0712000111'], $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/privacy/requests/{$id}/approve", [], $this->headersFor($this->admin))->assertOk();
        $this->postJson("/api/v1/privacy/requests/{$id}/execute", [], $this->headersFor())->assertOk()->assertJsonPath('data.note', null);

        $this->inTenant(function () use ($clerk, $id) {
            $event = DB::table('login_events')->where('user_id', $clerk->id)->first();
            $this->assertNull($event->ip);
            $this->assertNull($event->user_agent);
            $this->assertNull(DB::table('verification_challenges')->where('user_id', $clerk->id)->value('destination'));
            $this->assertNull(DB::table('privacy_requests')->where('id', $id)->value('note'));
        });
    }

    /** Review M5: a rejection's free-text reason stays on the request, never in the audit log. */
    public function test_a_rejection_reason_is_not_written_to_the_audit_log(): void
    {
        $id = $this->create('access');
        $this->postJson("/api/v1/privacy/requests/{$id}/reject", ['reason' => 'Caller could not confirm Amina\'s date of birth'], $this->headersFor($this->admin))->assertOk();

        $this->inTenant(function () use ($id) {
            $entry = AuditEntry::query()->where('action', 'core.privacy_request.reject')->where('auditable_id', $id)->firstOrFail();
            $this->assertStringNotContainsString('date of birth', json_encode([$entry->before, $entry->after]));
            $this->assertSame('Caller could not confirm Amina\'s date of birth', DB::table('privacy_requests')->where('id', $id)->value('rejection_reason'));
        });
    }

    /** Review (low): someone who reads consent only finds customers, never users or employees. */
    public function test_consent_readers_find_customers_only(): void
    {
        $reader = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Consent reader', ['core.consent.view']), Scope::tenant());

            return $user;
        });

        $this->getJson('/api/v1/privacy/subjects?type=customer&search=amina', $this->headersFor($reader))->assertOk()->assertJsonPath('data.0.id', $this->amina->id);
        $this->getJson('/api/v1/privacy/subjects?type=user', $this->headersFor($reader))->assertStatus(422);
    }

    public function test_the_requester_cannot_approve_and_only_approved_requests_run(): void
    {
        $id = $this->create('deletion');

        $this->postJson("/api/v1/privacy/requests/{$id}/execute", [], $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'not_approved');
        $this->postJson("/api/v1/privacy/requests/{$id}/approve", [], $this->headersFor())->assertForbidden()->assertJsonPath('code', 'own_request');
        $this->postJson("/api/v1/privacy/requests/{$id}/reject", ['reason' => 'Identity not proven'], $this->headersFor($this->admin))->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson("/api/v1/privacy/requests/{$id}/approve", [], $this->headersFor($this->admin))->assertStatus(422)->assertJsonPath('code', 'already_decided');

        $this->inTenant(fn () => $this->assertSame('Amina Otieno', DB::table('parties')->where('id', $this->amina->id)->value('name')));
    }

    public function test_every_step_is_audited(): void
    {
        $this->completed('access');

        $this->inTenant(function () {
            foreach (['core.privacy_request.create', 'core.privacy_request.approve', 'core.privacy_request.execute'] as $action) {
                $this->assertTrue(AuditEntry::query()->where('action', $action)->exists(), $action);
            }
        });
    }

    public function test_each_endpoint_needs_its_permission(): void
    {
        $clerk = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $id = $this->create('access');
        $headers = $this->headersFor($clerk);

        $this->getJson('/api/v1/privacy/requests', $headers)->assertForbidden();
        $this->getJson("/api/v1/privacy/requests/{$id}", $headers)->assertForbidden();
        $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'customer', 'subject_id' => $this->amina->id, 'kind' => 'access'], $headers)->assertForbidden();
        $this->postJson("/api/v1/privacy/requests/{$id}/approve", [], $headers)->assertForbidden();
        $this->postJson("/api/v1/privacy/requests/{$id}/reject", ['reason' => 'No'], $headers)->assertForbidden();
        $this->postJson("/api/v1/privacy/requests/{$id}/execute", [], $headers)->assertForbidden();
        $this->getJson("/api/v1/privacy/requests/{$id}/report", $headers)->assertForbidden();
        $this->getJson('/api/v1/privacy/subjects?type=customer', $headers)->assertForbidden();
    }

    public function test_another_tenants_subjects_and_requests_are_out_of_reach(): void
    {
        $other = $this->otherTenant();
        $theirs = $this->asTenant($other['user']->tenant_id, fn () => Party::query()->create([
            'company_id' => $other['company']->id, 'kind' => 'person', 'name' => 'Other Person', 'roles' => ['customer'],
        ]));
        $theirRequest = $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'customer', 'subject_id' => $theirs->id, 'kind' => 'access'], $this->headersFor($other['user']))
            ->assertCreated()->json('data.id');

        // Their customer is not ours to ask about.
        $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'customer', 'subject_id' => $theirs->id, 'kind' => 'access'], $this->headersFor())
            ->assertStatus(422)->assertJsonPath('code', 'subject_not_found');
        // Their request does not exist for us.
        $this->getJson("/api/v1/privacy/requests/{$theirRequest}", $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/privacy/requests/{$theirRequest}/approve", [], $this->headersFor($this->admin))->assertNotFound();
        $this->getJson("/api/v1/privacy/requests/{$theirRequest}/report", $this->headersFor())->assertNotFound();
        $this->assertNotContains($theirRequest, array_column($this->getJson('/api/v1/privacy/requests', $this->headersFor())->json('data'), 'id'));
        $this->assertSame([], array_values(array_filter(
            $this->getJson('/api/v1/privacy/subjects?type=customer&search=Other', $this->headersFor())->json('data'),
            fn ($s) => $s['id'] === $theirs->id,
        )));
    }

    public function test_subjects_are_searched_by_name(): void
    {
        $this->getJson('/api/v1/privacy/subjects?type=customer&search=amina', $this->headersFor())
            ->assertOk()->assertJsonPath('data.0.id', $this->amina->id)->assertJsonPath('data.0.name', 'Amina Otieno')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/privacy/subjects?type=planet', $this->headersFor())->assertStatus(422);
    }
}
