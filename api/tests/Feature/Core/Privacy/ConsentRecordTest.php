<?php

namespace Tests\Feature\Core\Privacy;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Parties\Party;
use App\Core\Privacy\Consents;
use App\Core\Privacy\Models\ConsentRecord;
use App\Core\Rbac\Scope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-08: marketing consent records. Append-only (a trigger refuses edits
 * and deletes); the latest record per purpose and channel is the current
 * state; audited; permission-denied and other-tenant cases.
 */
class ConsentRecordTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private Party $amina;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganisation();
        $this->amina = $this->inTenant(fn () => Party::query()->create([
            'company_id' => $this->acme->id, 'kind' => 'person', 'name' => 'Amina Otieno', 'roles' => ['customer'],
        ]));
    }

    private function record(bool $granted, string $channel = 'sms', ?array $headers = null): void
    {
        $this->postJson('/api/v1/privacy/consents', [
            'subject_type' => 'customer', 'subject_id' => $this->amina->id, 'purpose' => 'marketing',
            'channel' => $channel, 'granted' => $granted, 'evidence' => 'Asked at the till',
        ], $headers ?? $this->headersFor())->assertCreated()->assertJsonPath('data.granted', $granted)->assertJsonPath('data.source', 'back_office');
    }

    public function test_grant_withdraw_and_history(): void
    {
        $this->record(true, 'sms');
        $this->record(true, 'email');
        $this->record(false, 'sms');

        $data = $this->getJson('/api/v1/privacy/consents?subject_type=customer&subject_id='.$this->amina->id, $this->headersFor())->assertOk()->json('data');

        $this->assertCount(3, $data['history']);
        $current = collect($data['current'])->keyBy('channel');
        $this->assertFalse($current['sms']['granted']);
        $this->assertTrue($current['email']['granted']);
        $this->assertSame($this->owner->id, $data['history'][0]['recorded_by']['id']);

        $consents = app(Consents::class);
        $this->inTenant(function () use ($consents) {
            $this->assertFalse($consents->allows('customer', $this->amina->id, 'marketing', 'sms'));
            $this->assertTrue($consents->allows('customer', $this->amina->id, 'marketing', 'email'));
            $this->assertFalse($consents->allows('customer', $this->amina->id, 'marketing', 'whatsapp'));
            $this->assertSame(2, AuditEntry::query()->where('action', 'core.consent.grant')->count());
            $this->assertSame(1, AuditEntry::query()->where('action', 'core.consent.withdraw')->count());
        });
    }

    public function test_records_are_append_only(): void
    {
        $this->record(true);

        $this->inTenant(function () {
            $id = ConsentRecord::query()->value('id');
            try {
                DB::transaction(fn () => DB::table('consent_records')->where('id', $id)->update(['granted' => false]));
                $this->fail('The update was not refused.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
            try {
                DB::transaction(fn () => DB::table('consent_records')->where('id', $id)->delete());
                $this->fail('The delete was not refused.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        });
    }

    public function test_input_is_validated(): void
    {
        $this->postJson('/api/v1/privacy/consents', ['subject_type' => 'customer', 'subject_id' => $this->amina->id, 'purpose' => 'profiling', 'channel' => 'pigeon', 'granted' => 'maybe'], $this->headersFor())
            ->assertStatus(422)->assertJsonValidationErrors(['purpose', 'channel', 'granted']);
    }

    public function test_permissions_are_required(): void
    {
        $clerk = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $this->getJson('/api/v1/privacy/consents?subject_type=customer&subject_id='.$this->amina->id, $this->headersFor($clerk))->assertForbidden();
        $this->postJson('/api/v1/privacy/consents', ['subject_type' => 'customer', 'subject_id' => $this->amina->id, 'purpose' => 'marketing', 'channel' => 'sms', 'granted' => true], $this->headersFor($clerk))->assertForbidden();
    }

    public function test_another_tenants_customers_are_out_of_reach(): void
    {
        $other = $this->otherTenant();
        $this->record(true);

        // Our customer is unknown to them; their list of our customer's consent is empty.
        $this->postJson('/api/v1/privacy/consents', ['subject_type' => 'customer', 'subject_id' => $this->amina->id, 'purpose' => 'marketing', 'channel' => 'sms', 'granted' => false], $this->headersFor($other['user']))
            ->assertStatus(422)->assertJsonPath('code', 'subject_not_found');
        $this->getJson('/api/v1/privacy/consents?subject_type=customer&subject_id='.$this->amina->id, $this->headersFor($other['user']))
            ->assertOk()->assertJsonPath('data.history', [])->assertJsonPath('data.current', []);
    }
}
