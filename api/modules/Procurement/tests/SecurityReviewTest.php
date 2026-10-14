<?php

namespace Modules\Procurement\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Billing\Models\Subscription;
use App\Core\Rbac\Scope;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Procurement\Models\BidFile;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Stage 7B security review of Procurement: spreadsheet-safe performance
// CSV (EXP-01), bid file audit (PRC-09, PRC-24), the portal's
// Referrer-Policy and billing state (PRC-07, decision 21), bidder
// confidentiality before opening (decision 22), key rewrap across every
// tenant (ADR 019), invoice separation of duties (decision 23) and
// requisitions of another branch (RBAC-04).
class SecurityReviewTest extends TestCase
{
    use BuildsProcurement, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $this->setUpProcurement();
    }

    private function tender(array $extra = [], array $suppliers = []): array
    {
        $tender = $this->api('POST', 'procurement/tenders', [
            'branch_id' => $this->branchA->id, 'title' => 'Generators', 'kind' => 'invited', 'deadline_at' => '2026-10-20T12:00:00Z', 'opening_at' => '2026-10-20T12:00:00Z',
            'technical_weight' => 0, 'lines' => [['description' => 'Generator', 'qty' => '2']], 'openers' => [$this->owner->id, $this->manager->id], ...$extra,
        ])->assertCreated()->json('data');

        foreach ($suppliers as $supplier) {
            $this->api('POST', "procurement/tenders/{$tender['id']}/bidders", ['party_id' => $supplier->id])->assertCreated();
        }

        return $this->api('POST', "procurement/tenders/{$tender['id']}/publish")->assertOk()->json('data');
    }

    private function bidderSession(array $tender, int $index = 0): array
    {
        return $this->portal($this->linkFor($this->api('GET', "procurement/tenders/{$tender['id']}")->json("data.bidders.{$index}.id")));
    }

    private function bid(array $headers, array $tender): void
    {
        $this->putJson('/api/v1/supplier-portal/bid', ['currency' => 'KES', 'lines' => [['tender_line_id' => $tender['lines'][0]['id'], 'unit_price_minor' => '100000']]], $headers)->assertOk();
    }

    public function test_the_supplier_performance_csv_is_spreadsheet_safe(): void
    {
        $this->inTenant(fn () => $this->supplier->forceFill(['name' => '=HYPERLINK("http://x.example.com")'])->save());
        $order = $this->approvedOrder();
        $this->postedReceipt($order);

        $csv = $this->get('/api/v1/procurement/supplier-performance?from=2026-10-01&to=2026-10-31&format=csv', $this->headersFor())->assertOk()->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString("\n=HYPERLINK", $csv);
    }

    public function test_a_bid_files_name_type_size_and_path_never_reach_the_audit_log(): void
    {
        $tender = $this->tender([], [$this->supplier]);
        $headers = $this->bidderSession($tender);
        $this->post('/api/v1/supplier-portal/bid/files', ['file' => UploadedFile::fake()->create('price-schedule.pdf', 20, 'application/pdf')], $headers)->assertSuccessful();

        $this->inTenant(function () {
            $file = BidFile::query()->sole();
            $entries = AuditEntry::query()->where('auditable_id', $file->id)->get();
            $this->assertNotEmpty($entries);

            foreach ($entries as $entry) {
                $logged = json_encode([$entry->before, $entry->after]);
                $this->assertStringNotContainsString('price-schedule', $logged);

                foreach (['"mime"', '"size"', '"path"', '"name"'] as $key) {
                    $this->assertStringNotContainsString($key, $logged);
                }
            }
        });
    }

    public function test_portal_responses_send_no_referrer(): void
    {
        $tender = $this->tender([], [$this->supplier]);
        $headers = $this->bidderSession($tender);

        $this->getJson('/api/v1/supplier-portal/tender', $headers)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->postJson('/api/v1/supplier-portal/sessions', ['token' => str_repeat('a', 48)])->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_a_suspended_business_takes_no_bids_and_a_read_only_one_still_does(): void
    {
        $tender = $this->tender([], [$this->supplier]);
        $headers = $this->bidderSession($tender);
        $subscription = $this->inTenant(fn () => Subscription::query()->create(['country' => 'KE', 'currency' => 'KES', 'cycle' => 'monthly', 'status' => Subscription::READ_ONLY]));

        // Overdue (read-only): the portal stays open.
        $this->getJson('/api/v1/supplier-portal/tender', $headers)->assertOk()->assertJsonPath('data.tender.unavailable', false);
        $this->bid($headers, $tender);

        foreach ([Subscription::SUSPENDED, Subscription::CANCELLED] as $status) {
            $this->inTenant(fn () => $subscription->forceFill(['status' => $status])->save());
            $this->getJson('/api/v1/supplier-portal/tender', $headers)->assertOk()
                ->assertJsonPath('data.tender.unavailable', true)->assertJsonPath('data.tender.open_for_bids', false);
            $this->putJson('/api/v1/supplier-portal/bid', ['currency' => 'KES', 'lines' => [['tender_line_id' => $tender['lines'][0]['id'], 'unit_price_minor' => '90000']]], $headers)
                ->assertStatus(409)->assertJsonPath('code', 'tender_unavailable');
            $this->post('/api/v1/supplier-portal/bid/files', ['file' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')], [...$headers, 'Accept' => 'application/json'])
                ->assertStatus(409)->assertJsonPath('code', 'tender_unavailable');
        }
    }

    public function test_before_the_opening_staff_see_only_the_bid_count_and_invited_names(): void
    {
        $tender = $this->tender(['kind' => 'open'], [$this->supplier, $this->otherSupplier]);
        $notice = basename($this->api('GET', "procurement/tenders/{$tender['id']}")->json('data.notice_url'));
        $this->postJson("/api/v1/supplier-portal/notices/{$notice}/register", ['name' => 'Newco', 'email' => 'bids@newco.example.com', 'tax_id' => 'P051234567X'])->assertStatus(202);
        $this->bid($this->bidderSession($tender, 1), $tender);
        $this->bid($this->bidderSession($tender, 0), $tender);

        $detail = $this->api('GET', "procurement/tenders/{$tender['id']}")->assertOk()->json('data');
        $this->assertSame(2, $detail['bids_count']);
        $this->assertSame([], $detail['bids']);
        $this->assertSame(['Upesi Supplies', 'Baraka Wholesale'], array_column($detail['bidders'], 'name'));
        $this->assertStringNotContainsString('newco', strtolower(json_encode($detail)));
        $this->assertStringNotContainsString('P051234567X', json_encode($detail));
        $this->assertStringNotContainsString('example.com', json_encode($detail['bidders']));
        $this->assertArrayNotHasKey('last_seen_at', $detail['bidders'][0]);
        // The audit log names no bidder nor their email.
        $audit = $this->inTenant(fn () => AuditEntry::query()->where('action', 'like', 'procurement.%')->get()->map(fn ($e) => json_encode([$e->before, $e->after]))->implode("\n"));
        $this->assertStringNotContainsString('newco.example.com', $audit);
        $this->assertStringNotContainsString('P051234567X', $audit);

        // Opened: every bid, in the order submitted (then by id), and the registrant.
        $this->clockTo('2026-10-20T12:00:00Z');
        $this->api('POST', "procurement/tenders/{$tender['id']}/open")->assertOk();
        $opened = $this->api('POST', "procurement/tenders/{$tender['id']}/open", [], $this->manager)->assertOk()->json('data');
        $this->assertSame(['Baraka Wholesale', 'Upesi Supplies'], array_column(array_column($opened['bids'], 'bidder'), 'name'));
        $this->assertContains('Newco', array_column($opened['bidders'], 'name'));
    }

    public function test_rewrap_keys_reaches_suspended_tenants(): void
    {
        $tender = $this->tender([], [$this->supplier]);
        DB::connection('pgsql_owner')->table('tenants')->where('id', $this->owner->tenant_id)->update(['status' => 'suspended']);

        $old = (string) config('app.key');
        $new = 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'));
        config(['app.key' => $new, 'app.previous_keys' => [$old]]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->artisan('procurement:rewrap-keys')->assertSuccessful()->expectsOutputToContain('Tenders re-encrypted: 1; failed: 0');
        config(['app.previous_keys' => []]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        // Only the new key now: the tender key still opens.
        $secret = $this->inTenant(fn () => DB::table('procurement_tenders')->where('id', $tender['id'])->value('seal_secret'));
        $this->assertNotSame('', Crypt::decryptString((string) $secret));
    }

    public function test_whoever_enters_a_supplier_invoice_never_approves_it(): void
    {
        $accountant = $this->userWith('accountant', Scope::company($this->acme->id));
        $order = $this->approvedOrder();
        $this->postedReceipt($order, [['order_line_id' => $order['lines'][0]['id'], 'received_qty' => '10']]);
        // An exception (the delivery not received), entered by the accountant.
        $invoice = $this->api('POST', 'procurement/invoices', ['order_id' => $order['id'], 'supplier_reference' => 'SOD-1', 'invoice_date' => '2026-10-16',
            'lines' => [['order_line_id' => $order['lines'][0]['id'], 'qty' => '10'], ['order_line_id' => $order['lines'][1]['id'], 'qty' => '1']]], $accountant)->assertCreated()->json('data');

        $this->api('GET', "procurement/invoices/{$invoice['id']}", [], $accountant)->assertJsonPath('data.can.approve', false)->assertJsonPath('data.can.override', false);
        $this->api('POST', "procurement/invoices/{$invoice['id']}/approve", ['override_reason' => 'Agreed'], $accountant)
            ->assertForbidden()->assertJsonPath('code', 'invoice_approver_is_creator');
        $this->api('POST', "procurement/invoices/{$invoice['id']}/approve", ['override_reason' => 'Agreed'])->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_a_requisition_of_another_branch_cannot_be_taken_on(): void
    {
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $requisition = $this->api('POST', 'procurement/requisitions', ['branch_id' => $this->branchB->id, 'purpose' => 'Fuel',
            'lines' => [['item_id' => $this->soap->id, 'qty' => '5', 'estimated_unit_price_minor' => '10000']]], $managerB)->assertCreated()->json('data');
        $this->inTenant(fn () => DB::table('procurement_requisitions')->where('id', $requisition['id'])->update(['status' => 'approved']));

        // A branch A buyer: refused for a PO, an RFQ and a tender, with the same answer as an unknown requisition.
        $po = ['branch_id' => $this->branchA->id, 'party_id' => $this->supplier->id, 'requisition_id' => $requisition['id'],
            'lines' => [['item_id' => $this->soap->id, 'qty' => '5', 'unit_price_minor' => '10000']]];
        $this->api('POST', 'procurement/orders', $po, $this->buyer)->assertUnprocessable()->assertJsonPath('code', 'requisition_not_approved');
        $this->api('POST', 'procurement/orders', [...$po, 'requisition_id' => '01900000-0000-7000-8000-000000000000'], $this->buyer)->assertUnprocessable()->assertJsonPath('code', 'requisition_not_approved');
        $this->api('POST', 'procurement/rfqs', ['branch_id' => $this->branchA->id, 'title' => 'Fuel', 'deadline_at' => '2026-10-20T12:00:00Z', 'requisition_id' => $requisition['id'],
            'lines' => [['item_id' => $this->soap->id, 'qty' => '5']], 'supplier_ids' => [$this->supplier->id]], $this->buyer)
            ->assertUnprocessable()->assertJsonPath('code', 'requisition_not_approved');
        $this->api('POST', 'procurement/tenders', ['branch_id' => $this->branchA->id, 'title' => 'Fuel', 'kind' => 'invited', 'deadline_at' => '2026-10-20T12:00:00Z',
            'opening_at' => '2026-10-20T12:00:00Z', 'technical_weight' => 0, 'requisition_id' => $requisition['id'], 'lines' => [['description' => 'Fuel', 'qty' => '5']],
            'openers' => [$this->owner->id, $this->manager->id]])->assertUnprocessable()->assertJsonPath('code', 'requisition_not_approved');

        // Branch B's own buyer may.
        $buyerB = $this->userWith('procurement_officer', Scope::branch($this->branchB->id));
        $this->api('POST', 'procurement/orders', [...$po, 'branch_id' => $this->branchB->id], $buyerB)->assertCreated();
        $this->inTenant(fn () => $this->assertSame('ordered', DB::table('procurement_requisitions')->where('id', $requisition['id'])->value('status')));
    }

    /** Review: a place the user does not reach answers the translated refusal, never a raw key. */
    public function test_a_branch_the_buyer_does_not_reach_is_refused_in_words(): void
    {
        $response = $this->api('POST', 'procurement/landed-costs', ['branch_id' => $this->branchB->id, 'cost_date' => '2026-10-15', 'method' => 'value',
            'charges' => [['description' => 'Freight', 'currency' => 'KES', 'amount_minor' => '1000']], 'receipt_ids' => ['01900000-0000-7000-8000-000000000000']], $this->buyer)->assertForbidden();

        $this->assertSame(__('rbac.forbidden'), $response->json('message'));
        $this->assertStringNotContainsString('rbac.', (string) $response->json('message'));
    }
}
