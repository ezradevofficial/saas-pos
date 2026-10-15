<?php

namespace Modules\Procurement\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\TenantCurrencies;
use App\Core\DataExport\ExportColumns;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Rbac\Scope;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\PortalSession;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Models\TenderOpening;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// The review of feat/procurement: registration without enumeration (PRC-08),
// key handling and seal binding (PRC-09, PRC-10, ADR 019), evaluation over
// the whole committee (PRC-11), landed cost scope (PRC-17, PRC-22), portal
// limits, currencies and sliding sessions (PRC-07, PRC-23), conflicts of
// interest, bid file names, uploads, and the committee pickers.
class ReviewFixesTest extends TestCase
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

    private function bid(array $headers, array $tender, string $price = '100000', string $currency = 'KES'): void
    {
        $this->putJson('/api/v1/supplier-portal/bid', ['currency' => $currency, 'lines' => [['tender_line_id' => $tender['lines'][0]['id'], 'unit_price_minor' => $price]]], $headers)->assertOk();
    }

    public function test_registration_always_answers_the_same_and_resends_a_known_link(): void
    {
        $tender = $this->tender(['kind' => 'open']);
        $notice = basename($this->api('GET', "procurement/tenders/{$tender['id']}")->json('data.notice_url'));
        $register = fn (string $email) => $this->postJson("/api/v1/supplier-portal/notices/{$notice}/register", ['name' => 'Newco', 'email' => $email])
            ->assertStatus(202)->assertExactJson(['data' => ['sent' => true]]);

        $register('bids@newco.example.com');
        $register('BIDS@newco.example.com');
        // A committee member's email gets the same answer and nothing.
        $register((string) $this->manager->email);

        $this->inTenant(function () {
            $this->assertSame(2, NotificationDelivery::query()->where('event_type', 'procurement.portal.invited')->where('recipient', 'bids@newco.example.com')->count());
            $this->assertSame(1, Bidder::query()->count());
            $this->assertSame(['registered', 'link_resent', 'conflict_of_interest'], AuditEntry::query()->where('action', 'procurement.portal.registration')->orderBy('seq')->get()->pluck('after.outcome')->all());
        });
    }

    public function test_an_unreadable_tender_key_stops_the_opening_and_marks_nothing_tampered(): void
    {
        $tender = $this->tender([], [$this->supplier]);
        $this->bid($this->bidderSession($tender), $tender);
        // The key was wrapped with an application key that is gone.
        $this->inTenant(fn () => DB::table('procurement_tenders')->where('id', $tender['id'])->update(['seal_secret' => (new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC'))->encryptString('x')]));
        $this->clockTo('2026-10-20T12:00:00Z');

        $this->api('POST', "procurement/tenders/{$tender['id']}/open")->assertOk();
        $this->api('POST', "procurement/tenders/{$tender['id']}/open", [], $this->manager)->assertStatus(500)->assertJsonPath('code', 'seal_key_unavailable');

        $this->inTenant(function () use ($tender) {
            $this->assertSame(Bid::SUBMITTED, Bid::query()->sole()->status);
            $this->assertSame(Tender::PUBLISHED, Tender::query()->findOrFail($tender['id'])->status);
            $this->assertSame(1, TenderOpening::query()->count());
        });
    }

    public function test_a_sealed_payload_moved_to_another_bid_is_tampered(): void
    {
        $tender = $this->tender([], [$this->supplier, $this->otherSupplier]);
        $this->bid($this->bidderSession($tender, 0), $tender, '100000');
        $this->bid($this->bidderSession($tender, 1), $tender, '900000');
        // Someone with database access swaps the two bids' ciphertexts and fingerprints.
        $this->inTenant(function () {
            [$a, $b] = Bid::query()->orderBy('id')->get()->all();
            DB::table('procurement_bids')->where('id', $a->id)->update(['sealed_payload' => $b->sealed_payload, 'fingerprint' => $b->fingerprint]);
            DB::table('procurement_bids')->where('id', $b->id)->update(['sealed_payload' => $a->sealed_payload, 'fingerprint' => $a->fingerprint]);
        });
        $this->clockTo('2026-10-20T12:00:00Z');
        $this->api('POST', "procurement/tenders/{$tender['id']}/open")->assertOk();
        $opened = $this->api('POST', "procurement/tenders/{$tender['id']}/open", [], $this->manager)->assertOk()->json('data');

        $this->assertSame(['tampered', 'tampered'], array_column($opened['bids'], 'status'));
    }

    public function test_the_notice_token_is_random_and_keys_survive_an_app_key_rotation(): void
    {
        $tender = $this->tender(['kind' => 'open'], [$this->supplier]);
        $this->bid($this->bidderSession($tender), $tender);
        $notice = basename($this->api('GET', "procurement/tenders/{$tender['id']}")->json('data.notice_url'));
        $this->inTenant(fn () => $this->assertSame(hash('sha256', $notice), Tender::query()->findOrFail($tender['id'])->public_token_hash));
        $this->assertNotSame(substr(hash_hmac('sha256', 'procurement-tender-notice:'.$this->owner->tenant_id.':'.$tender['id'], (string) config('app.key')), 0, 48), $notice);

        // Rotate APP_KEY, the old one kept in APP_PREVIOUS_KEYS, then re-wrap and drop the old key.
        $old = (string) config('app.key');
        $new = 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'));
        config(['app.key' => $new, 'app.previous_keys' => [$old]]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->artisan('procurement:rewrap-keys')->assertSuccessful();
        config(['app.previous_keys' => []]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $this->assertSame($notice, basename($this->api('GET', "procurement/tenders/{$tender['id']}")->json('data.notice_url')));
        $this->clockTo('2026-10-20T12:00:00Z');
        $this->api('POST', "procurement/tenders/{$tender['id']}/open")->assertOk();
        $this->assertSame('opened', $this->api('POST', "procurement/tenders/{$tender['id']}/open", [], $this->manager)->assertOk()->json('data.bids.0.status'));
    }

    public function test_the_tender_is_evaluated_only_when_every_evaluator_scored_every_bid(): void
    {
        $tender = $this->tender(['technical_weight' => 50, 'criteria' => [['name' => 'Quality', 'weight' => 100, 'max_score' => 10]],
            'evaluators' => [$this->owner->id, $this->manager->id]], [$this->supplier]);
        $this->bid($this->bidderSession($tender), $tender);
        $this->clockTo('2026-10-20T12:00:00Z');
        $this->api('POST', "procurement/tenders/{$tender['id']}/open")->assertOk();
        $opened = $this->api('POST', "procurement/tenders/{$tender['id']}/open", [], $this->manager)->assertOk()->json('data');
        $scores = ['scores' => [['criterion_id' => $opened['criteria'][0]['id'], 'score' => 6]]];

        $afterOne = $this->api('PUT', "procurement/bids/{$opened['bids'][0]['id']}/scores", $scores)->assertOk()->json('data');
        $this->assertSame('opened', $afterOne['status']);
        $this->assertNull($afterOne['bids'][0]['technical_score_bp']);

        $afterBoth = $this->api('PUT', "procurement/bids/{$opened['bids'][0]['id']}/scores", ['scores' => [['criterion_id' => $opened['criteria'][0]['id'], 'score' => 8]]], $this->manager)->assertOk()->json('data');
        $this->assertSame('evaluated', $afterBoth['status']);
        $this->assertSame(7000, $afterBoth['bids'][0]['technical_score_bp']);
    }

    public function test_a_landed_cost_takes_only_grns_in_the_users_scope_and_shows_only_those(): void
    {
        $order = $this->approvedOrder();
        $receipt = $this->postedReceipt($order);
        $buyerB = $this->userWith('procurement_officer', Scope::branch($this->branchB->id));
        $body = ['branch_id' => $this->branchB->id, 'cost_date' => '2026-10-15', 'method' => 'value',
            'charges' => [['description' => 'Freight', 'party_id' => $this->otherSupplier->id, 'bill_reference' => 'FR-1', 'currency' => 'KES', 'amount_minor' => '1000']], 'receipt_ids' => [$receipt['id']]];

        $this->api('POST', 'procurement/landed-costs', $body, $buyerB)->assertUnprocessable()->assertJsonPath('code', 'receipt_unknown');

        // Made by the owner at branch B, it shows branch B's buyer nothing of branch A's GRN.
        $landed = $this->api('POST', 'procurement/landed-costs', $body)->assertCreated()->json('data');
        $seen = $this->api('GET', "procurement/landed-costs/{$landed['id']}", [], $buyerB)->assertOk()->json('data');
        $this->assertSame([], $seen['receipts']);
        $this->assertSame([], $seen['allocation']['lines']);
        $this->assertCount(1, $this->api('GET', "procurement/landed-costs/{$landed['id']}")->json('data.receipts'));
    }

    public function test_the_allocation_error_names_the_line(): void
    {
        $receipt = $this->postedReceipt($this->approvedOrder());
        $landed = $this->api('POST', 'procurement/landed-costs', ['branch_id' => $this->branchA->id, 'cost_date' => '2026-10-15', 'method' => 'weight',
            'charges' => [['description' => 'Freight', 'party_id' => $this->otherSupplier->id, 'bill_reference' => 'FR-2', 'currency' => 'KES', 'amount_minor' => '1000']], 'receipt_ids' => [$receipt['id']]])->assertCreated()->json('data');

        $this->assertStringContainsString($receipt['number'].' / 1', $landed['allocation']['error']['message']);
    }

    public function test_the_portal_is_limited_per_ip_as_well_as_per_token(): void
    {
        config(['procurement.portal.per_ip_per_minute' => 3]);
        $tender = $this->tender([], [$this->supplier, $this->otherSupplier]);
        $one = $this->bidderSession($tender, 0);
        $two = $this->bidderSession($tender, 1);

        $this->getJson('/api/v1/supplier-portal/tender', $one)->assertOk();
        $this->getJson('/api/v1/supplier-portal/tender', $two)->assertOk();
        $this->getJson('/api/v1/supplier-portal/tender', $one)->assertOk();
        // A fourth request from the same address, whatever the token.
        $this->getJson('/api/v1/supplier-portal/tender', $two)->assertStatus(429);
    }

    public function test_a_bid_needs_a_currency_with_a_rate_and_the_portal_offers_the_active_ones(): void
    {
        $this->inTenant(fn () => app(TenantCurrencies::class)->activate('EUR'));
        $tender = $this->tender([], [$this->supplier]);
        $headers = $this->bidderSession($tender);

        $this->putJson('/api/v1/supplier-portal/bid', ['currency' => 'EUR', 'lines' => [['tender_line_id' => $tender['lines'][0]['id'], 'unit_price_minor' => '100']]], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'rate_unavailable');
        $view = $this->getJson('/api/v1/supplier-portal/tender', $headers)->assertOk();
        $this->assertSame(['EUR', 'KES', 'USD'], $view->json('data.currencies'));
        $this->assertSame('Africa/Nairobi', $view->json('data.tender.timezone') ?? 'Africa/Nairobi');
        $this->assertNotNull($view->json('data.tender.timezone'));
    }

    public function test_sessions_slide_with_use_and_end_when_idle(): void
    {
        $tender = $this->tender([], [$this->supplier]);
        $headers = $this->bidderSession($tender);

        $this->clockTo('2026-10-15T16:00:00Z');
        $this->getJson('/api/v1/supplier-portal/tender', $headers)->assertOk();
        // 7 hours after the last use it is still alive (it started 14 hours ago).
        $this->clockTo('2026-10-15T23:00:00Z');
        $this->getJson('/api/v1/supplier-portal/tender', $headers)->assertOk();
        // 8 hours idle: ended.
        $this->clockTo('2026-10-16T07:00:01Z');
        $this->getJson('/api/v1/supplier-portal/tender', $headers)->assertUnauthorized();
        $this->inTenant(fn () => $this->assertSame('2026-10-16T07:00:00+00:00', PortalSession::query()->sole()->expires_at->toIso8601String()));
    }

    public function test_bidders_and_committee_members_never_overlap(): void
    {
        $draft = $this->api('POST', 'procurement/tenders', [
            'branch_id' => $this->branchA->id, 'title' => 'Desks', 'kind' => 'invited', 'deadline_at' => '2026-10-20T12:00:00Z', 'opening_at' => '2026-10-20T12:00:00Z',
            'technical_weight' => 0, 'lines' => [['description' => 'Desk', 'qty' => '2']], 'openers' => [$this->owner->id, $this->manager->id],
        ])->assertCreated()->json('data');

        $this->api('POST', "procurement/tenders/{$draft['id']}/bidders", ['name' => 'Manager Co', 'email' => strtoupper((string) $this->manager->email)])
            ->assertUnprocessable()->assertJsonPath('code', 'conflict_of_interest');

        $evaluator = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->api('POST', "procurement/tenders/{$draft['id']}/bidders", ['name' => 'Evaluator Co', 'email' => (string) $evaluator->email])->assertCreated();
        $this->api('PATCH', "procurement/tenders/{$draft['id']}", ['evaluators' => [$evaluator->id]])->assertUnprocessable()->assertJsonPath('code', 'conflict_of_interest');
    }

    public function test_bid_file_names_stay_out_of_the_audit_and_unopened_exports_and_uploads_can_be_removed(): void
    {
        $tender = $this->tender([], [$this->supplier]);
        $headers = $this->bidderSession($tender);
        $file = $this->postJson('/api/v1/supplier-portal/bid/files', ['file' => UploadedFile::fake()->createWithContent('secret-price-list.txt', 'x')], $headers)->assertCreated()->json('data.id');

        $this->inTenant(fn () => $this->assertStringNotContainsString('secret-price-list', AuditEntry::query()->get()->map(fn ($e) => json_encode([$e->before, $e->after]))->implode("\n")));
        foreach (['name', 'mime', 'size'] as $column) {
            $this->assertArrayHasKey("procurement_bid_files.{$column}", ExportColumns::TRANSFORMS);
        }
        $exported = $this->inTenant(fn () => DB::selectOne('select '.ExportColumns::TRANSFORMS['procurement_bid_files.name'].' as name from procurement_bid_files t'));
        $this->assertNull($exported->name);

        // Removed before submitting; then nine more fit and the eleventh is refused.
        $this->deleteJson("/api/v1/supplier-portal/bid/files/{$file}", [], $headers)->assertOk()->assertJsonPath('data.files', []);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/supplier-portal/bid/files', ['file' => UploadedFile::fake()->createWithContent("f{$i}.txt", 'x')], $headers)->assertCreated();
        }
        $this->postJson('/api/v1/supplier-portal/bid/files', ['file' => UploadedFile::fake()->createWithContent('f10.txt', 'x')], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'too_many_files');
    }

    public function test_committee_candidates_need_only_procurement_permissions(): void
    {
        $candidates = $this->api('GET', "procurement/branches/{$this->branchA->id}/committee-candidates?role=opener", [], $this->buyer)->assertOk()->json('data');
        $ids = array_column($candidates, 'id');

        $this->assertContains($this->manager->id, $ids);
        $this->api('GET', "procurement/branches/{$this->branchA->id}/committee-candidates", [], $this->userWith('cashier', Scope::branch($this->branchA->id)))->assertForbidden();
    }
}
