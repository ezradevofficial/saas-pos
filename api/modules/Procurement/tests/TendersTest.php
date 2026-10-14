<?php

namespace Modules\Procurement\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Scope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Modules\Procurement\Events\TenderAwarded;
use Modules\Procurement\Events\TenderOpened;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\BidFile;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Tenders\Evaluation;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PRC-06 to PRC-12, PRC-24: tenders, sealed bids through the portal, the
// timed opening by two openers, scored evaluation, award and letters.
class TendersTest extends TestCase
{
    use BuildsProcurement, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $this->setUpProcurement();
    }

    /** A published invited tender with two bidders, the owner and the manager as openers, the owner as evaluator. */
    private function publishedTender(array $extra = []): array
    {
        $tender = $this->api('POST', 'procurement/tenders', [
            'branch_id' => $this->branchA->id,
            'title' => 'Office chairs',
            'kind' => 'invited',
            'deadline_at' => '2026-10-20T12:00:00Z',
            'opening_at' => '2026-10-20T14:00:00Z',
            'technical_weight' => 40,
            'pass_mark' => 50,
            'lines' => [['item_id' => $this->soap->id, 'qty' => '10'], ['description' => 'Assembly', 'qty' => '1']],
            'criteria' => [['name' => 'Experience', 'weight' => 60, 'max_score' => 10], ['name' => 'Delivery plan', 'weight' => 40, 'max_score' => 5]],
            'openers' => [$this->owner->id, $this->manager->id],
            'evaluators' => [$this->owner->id],
            ...$extra,
        ])->assertCreated()->json('data');
        $this->api('POST', "procurement/tenders/{$tender['id']}/bidders", ['party_id' => $this->supplier->id])->assertCreated();
        $this->api('POST', "procurement/tenders/{$tender['id']}/bidders", ['party_id' => $this->otherSupplier->id])->assertCreated();

        return $this->api('POST', "procurement/tenders/{$tender['id']}/publish")->assertOk()->json('data');
    }

    /** @return array{0: array, 1: array} the portal headers of the two bidders */
    private function bidders(array $tender): array
    {
        $bidders = collect($this->api('GET', "procurement/tenders/{$tender['id']}")->json('data.bidders'))->keyBy('name');

        return [$this->portal($this->linkFor($bidders['Upesi Supplies']['id'])), $this->portal($this->linkFor($bidders['Baraka Wholesale']['id']))];
    }

    private function bid(array $headers, array $tender, array $prices, string $currency = 'KES', string $technical = 'Ten years of chairs.', array $files = []): array
    {
        return $this->putJson('/api/v1/supplier-portal/bid', [
            'currency' => $currency,
            'lines' => array_map(fn (array $line, string $price) => ['tender_line_id' => $line['id'], 'unit_price_minor' => $price], $tender['lines'], $prices),
            'technical_response' => $technical,
            'file_ids' => $files,
        ], $headers)->assertOk()->json('data');
    }

    public function test_a_tender_needs_lines_weights_two_openers_and_a_future_deadline_to_publish(): void
    {
        $base = ['branch_id' => $this->branchA->id, 'title' => 'Chairs', 'kind' => 'invited', 'deadline_at' => '2026-10-20T12:00:00Z',
            'opening_at' => '2026-10-20T12:00:00Z', 'lines' => [['description' => 'Chair', 'qty' => '5']]];
        // The opening never precedes the deadline.
        $this->api('POST', 'procurement/tenders', [...$base, 'opening_at' => '2026-10-19T12:00:00Z'])->assertUnprocessable()->assertJsonValidationErrors('opening_at');

        $tender = $this->api('POST', 'procurement/tenders', [...$base, 'criteria' => [['name' => 'Quality', 'weight' => 70]], 'openers' => [$this->owner->id]])->assertCreated()->json('data');
        $this->assertSame(70, $tender['technical_weight']);
        $this->assertSame(30, $tender['financial_weight']);
        $this->api('POST', "procurement/tenders/{$tender['id']}/publish")->assertUnprocessable()->assertJsonPath('code', 'criteria_weights');

        $this->api('PATCH', "procurement/tenders/{$tender['id']}", ['criteria' => [['name' => 'Quality', 'weight' => 100]], 'evaluators' => [$this->owner->id]])->assertOk();
        $this->api('POST', "procurement/tenders/{$tender['id']}/publish")->assertUnprocessable()->assertJsonPath('code', 'openers_required');

        // A committee member must hold the permission at the branch: a cashier cannot open bids.
        $cashier = $this->userWith('cashier', Scope::branch($this->branchA->id));
        $this->api('PATCH', "procurement/tenders/{$tender['id']}", ['openers' => [$this->owner->id, $cashier->id]])->assertUnprocessable()->assertJsonPath('code', 'member_not_allowed');
        $this->api('PATCH', "procurement/tenders/{$tender['id']}", ['openers' => [$this->owner->id, $this->manager->id]])->assertOk();

        $published = $this->api('POST', "procurement/tenders/{$tender['id']}/publish")->assertOk()->json('data');
        $this->assertSame('published', $published['status']);
        $this->assertMatchesRegularExpression('/^TND-/', $published['number']);
        // Published tenders keep their terms.
        $this->api('PATCH', "procurement/tenders/{$tender['id']}", ['title' => 'Other'])->assertStatus(409);
    }

    public function test_sealed_bids_are_unreadable_until_two_openers_open_them_at_the_opening_time(): void
    {
        Event::fake([TenderOpened::class]);
        $tender = $this->publishedTender();
        [$upesi, $baraka] = $this->bidders($tender);

        $file = $this->postJson('/api/v1/supplier-portal/bid/files', ['file' => UploadedFile::fake()->createWithContent('plan.txt', 'Our secret delivery plan')], $upesi)->assertCreated()->json('data');
        $receipt = $this->bid($upesi, $tender, ['12345', '777777'], files: [$file['id']]);
        $this->assertSame('submitted', $receipt['bid']['status']);
        $this->assertSame(64, strlen($receipt['bid']['fingerprint']));
        // A new version replaces the old one.
        $receipt = $this->bid($upesi, $tender, ['12000', '777777'], files: [$file['id']]);
        $this->assertSame(2, $receipt['bid']['version']);
        $this->bid($baraka, $tender, ['15000', '500000'], technical: 'Fast delivery.');

        // PRC-09: at rest, only ciphertext; the plain columns are empty and the file is sealed.
        $this->inTenant(function () {
            foreach (Bid::query()->get() as $bid) {
                $this->assertNull($bid->total_minor);
                $this->assertNull($bid->lines);
                $this->assertStringNotContainsString('12000', $bid->sealed_payload);
                $this->assertStringNotContainsString('chairs', $bid->sealed_payload);
            }

            $stored = Storage::disk('media')->get(BidFile::query()->sole()->path);
            $this->assertStringNotContainsString('secret delivery', (string) $stored);
        });

        // Buyers see how many bids exist (decision 22: not who sent them), never their content, in the detail, the list and its export.
        $detail = $this->api('GET', "procurement/tenders/{$tender['id']}")->assertOk();
        $this->assertSame(2, $detail->json('data.bids_count'));
        $this->assertSame([], $detail->json('data.bids'));
        $this->assertStringNotContainsString('12000', $detail->getContent());
        $this->assertStringNotContainsString('Ten years', $detail->getContent());
        $csv = $this->get('/api/v1/procurement/tenders?format=csv', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringNotContainsString('12000', $csv);
        $bidId = $this->inTenant(fn () => Bid::query()->whereHas('files')->value('id'));
        $this->get("/api/v1/procurement/bids/{$bidId}/files/{$file['id']}", $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'bid_sealed');
        // Nor the audit log.
        $this->inTenant(function () {
            $log = AuditEntry::query()->where('module', 'procurement')->get()->map(fn ($e) => json_encode([$e->before, $e->after]))->implode("\n");
            $this->assertStringNotContainsString('12000', $log);
            $this->assertStringNotContainsString('Ten years', $log);
            $this->assertStringNotContainsString('sealed_payload', $log);
        });

        // Not before the opening time; then two different openers.
        $this->clockTo('2026-10-20T13:00:00Z');
        // After the deadline the session has ended and the link no longer opens one: nothing can change.
        $this->putJson('/api/v1/supplier-portal/bid', ['currency' => 'KES', 'lines' => [['tender_line_id' => $tender['lines'][0]['id'], 'unit_price_minor' => '1']]], $upesi)->assertUnauthorized();
        $this->api('POST', "procurement/tenders/{$tender['id']}/open")->assertStatus(409)->assertJsonPath('code', 'opening_not_due');
        $this->clockTo('2026-10-20T14:00:00Z');
        $once = $this->api('POST', "procurement/tenders/{$tender['id']}/open")->assertOk()->json('data');
        // Past its deadline a published tender shows as closed until it is opened.
        $this->assertSame('closed', $once['status']);
        // One opener is not the opening: still no bid shown (decision 22).
        $this->assertSame([], $once['bids']);
        $this->api('POST', "procurement/tenders/{$tender['id']}/open")->assertStatus(409)->assertJsonPath('code', 'already_confirmed');
        // Someone not on the committee may not confirm (even with the permission).
        $outsider = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->api('POST', "procurement/tenders/{$tender['id']}/open", [], $outsider)->assertForbidden();

        $opened = $this->api('POST', "procurement/tenders/{$tender['id']}/open", [], $this->manager)->assertOk()->json('data');
        $this->assertSame('opened', $opened['status']);
        $bids = collect($opened['bids'])->keyBy(fn ($bid) => $bid['bidder']['name']);
        // 10 × KES 120.00 + 1 × KES 7,777.77; the late try at 13:00 changed nothing.
        $this->assertSame(['amount_minor' => '897777', 'currency' => 'KES'], $bids['Upesi Supplies']['total']);
        $this->assertSame('Ten years of chairs.', $bids['Upesi Supplies']['technical_response']);
        $this->assertSame(['amount_minor' => '650000', 'currency' => 'KES'], $bids['Baraka Wholesale']['total']);
        $this->assertSame('Our secret delivery plan', $this->get("/api/v1/procurement/bids/{$bids['Upesi Supplies']['id']}/files/{$file['id']}", $this->headersFor())->assertOk()->getContent());
        Event::assertDispatched(TenderOpened::class, fn (TenderOpened $e) => $e->bids === 2 && count($e->openerIds) === 2);
        $this->inTenant(fn () => $this->assertTrue(AuditEntry::query()->where('action', 'procurement.tender.opened')->exists()));
    }

    public function test_a_bid_in_another_currency_shows_its_base_total_in_the_base_currency(): void
    {
        // CUR-04, PRC-11: a USD bid is converted to KES; the base total is labelled KES, not USD.
        $tender = $this->publishedTender();
        [$upesi] = $this->bidders($tender);
        $this->bid($upesi, $tender, ['100', '1000'], 'USD');

        $this->clockTo('2026-10-20T14:00:00Z');
        $this->api('POST', "procurement/tenders/{$tender['id']}/open")->assertOk();
        $opened = $this->api('POST', "procurement/tenders/{$tender['id']}/open", [], $this->manager)->assertOk()->json('data');

        // 10 × USD 1.00 + 1 × USD 10.00 = USD 20.00 = KES 2,600.00 at 130.
        $this->assertSame(['amount_minor' => '2000', 'currency' => 'USD'], $opened['bids'][0]['total']);
        $this->assertSame(['amount_minor' => '260000', 'currency' => 'KES'], $opened['bids'][0]['base_total']);
    }

    public function test_a_tampered_bid_is_not_opened(): void
    {
        $tender = $this->publishedTender();
        [$upesi] = $this->bidders($tender);
        $this->bid($upesi, $tender, ['100', '100']);
        $this->inTenant(fn () => DB::table('procurement_bids')->update(['sealed_payload' => DB::raw("sealed_payload || 'x'")]));

        $this->clockTo('2026-10-20T14:00:00Z');
        $this->api('POST', "procurement/tenders/{$tender['id']}/open")->assertOk();
        $opened = $this->api('POST', "procurement/tenders/{$tender['id']}/open", [], $this->manager)->assertOk()->json('data');
        $this->assertSame('tampered', $opened['bids'][0]['status']);
        $this->assertTrue($opened['bids'][0]['sealed']);
    }

    public function test_scoring_ranks_bids_and_the_award_makes_a_po_and_letters(): void
    {
        Event::fake([TenderAwarded::class]);
        $tender = $this->publishedTender();
        [$upesi, $baraka] = $this->bidders($tender);
        $this->bid($upesi, $tender, ['10000', '100000']);
        $this->bid($baraka, $tender, ['12000', '100000']);
        $this->clockTo('2026-10-20T14:00:00Z');
        $this->api('POST', "procurement/tenders/{$tender['id']}/open")->assertOk();
        $opened = $this->api('POST', "procurement/tenders/{$tender['id']}/open", [], $this->manager)->assertOk()->json('data');
        $bids = collect($opened['bids'])->keyBy(fn ($bid) => $bid['bidder']['name']);
        [$experience, $plan] = array_column($opened['criteria'], 'id');

        // Only evaluators score; scores stay within the criterion's maximum.
        $this->api('PUT', "procurement/bids/{$bids['Upesi Supplies']['id']}/scores", ['scores' => [['criterion_id' => $experience, 'score' => 11]]])->assertUnprocessable()->assertJsonPath('code', 'score_out_of_range');
        $this->api('PUT', "procurement/bids/{$bids['Upesi Supplies']['id']}/scores", ['scores' => [['criterion_id' => $experience, 'score' => 5]]], $this->manager)->assertForbidden();
        $this->api('POST', "procurement/bids/{$bids['Upesi Supplies']['id']}/award")->assertStatus(409)->assertJsonPath('code', 'not_evaluated');

        // Upesi: 6/10 × 60 + 4/5 × 40 = 68; Baraka: 10/10 × 60 + 5/5 × 40 = 100.
        $this->api('PUT', "procurement/bids/{$bids['Upesi Supplies']['id']}/scores", ['scores' => [['criterion_id' => $experience, 'score' => 6], ['criterion_id' => $plan, 'score' => 4]]])->assertOk();
        $ranked = $this->api('PUT', "procurement/bids/{$bids['Baraka Wholesale']['id']}/scores", ['scores' => [['criterion_id' => $experience, 'score' => 10], ['criterion_id' => $plan, 'score' => 5, 'comment' => 'Clear plan']]])->assertOk()->json('data');
        $this->assertSame('evaluated', $ranked['status']);
        $scored = collect($ranked['bids'])->keyBy(fn ($bid) => $bid['bidder']['name']);
        $this->assertSame(6800, $scored['Upesi Supplies']['technical_score_bp']);
        $this->assertSame(10000, $scored['Baraka Wholesale']['technical_score_bp']);
        // Financial: Upesi is the lowest (2,000.00 against 2,200.00): 100 and 2000/2200 = 90.91.
        $this->assertSame(10000, $scored['Upesi Supplies']['financial_score_bp']);
        $this->assertSame(9091, $scored['Baraka Wholesale']['financial_score_bp']);
        // Combined: 68 × 0.4 + 100 × 0.6 = 87.20; 100 × 0.4 + 90.91 × 0.6 = 94.55.
        $this->assertSame(8720, $scored['Upesi Supplies']['combined_score_bp']);
        $this->assertSame(9455, $scored['Baraka Wholesale']['combined_score_bp']);
        $this->assertSame(1, $scored['Baraka Wholesale']['rank']);

        // Awarding another than the top-ranked needs a reason; buyers may not award.
        $this->api('POST', "procurement/bids/{$scored['Upesi Supplies']['id']}/award")->assertUnprocessable()->assertJsonPath('code', 'award_reason_required');
        $this->api('POST', "procurement/bids/{$scored['Baraka Wholesale']['id']}/award", [], $this->buyer)->assertForbidden();
        $awarded = $this->api('POST', "procurement/bids/{$scored['Baraka Wholesale']['id']}/award")->assertOk()->json('data');
        $this->assertSame('awarded', $awarded['status']);
        $outcomes = collect($awarded['bids'])->mapWithKeys(fn ($bid) => [$bid['bidder']['name'] => $bid['outcome']])->all();
        ksort($outcomes);
        $this->assertSame(['Baraka Wholesale' => 'awarded', 'Upesi Supplies' => 'regretted'], $outcomes);

        $order = $this->inTenant(fn () => Order::query()->where('bid_id', $scored['Baraka Wholesale']['id'])->sole());
        $this->assertSame('draft', $order->status);
        $this->assertSame($this->otherSupplier->id, $order->party_id);
        $this->assertSame(220000, $order->subtotal_minor);
        Event::assertDispatched(TenderAwarded::class);

        // PRC-12: the letters print and email; nothing is decided before the award.
        $this->get("/api/v1/procurement/bids/{$scored['Baraka Wholesale']['id']}/letter", $this->headersFor())->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/api/v1/procurement/bids/{$scored['Upesi Supplies']['id']}/letter", $this->headersFor())->assertOk();
        $this->api('POST', "procurement/bids/{$scored['Upesi Supplies']['id']}/letter/email")->assertStatus(202)->assertJsonPath('data.sent_to', 'sales@upesi.example.com');
    }

    public function test_an_open_tender_takes_registrations_from_its_public_notice(): void
    {
        $tender = $this->publishedTender(['kind' => 'open']);
        $url = $this->api('GET', "procurement/tenders/{$tender['id']}")->json('data.notice_url');
        $notice = substr($url, strrpos($url, '/') + 1);

        $public = $this->getJson("/api/v1/supplier-portal/notices/{$notice}")->assertOk();
        $this->assertSame('Office chairs', $public->json('data.title'));
        $this->assertArrayNotHasKey('bidders', $public->json('data'));
        $this->assertStringNotContainsString('upesi', $public->getContent());

        $this->postJson("/api/v1/supplier-portal/notices/{$notice}/register", ['name' => 'Newco Ltd', 'email' => 'tenders@newco.example.com', 'tax_id' => 'P051234567X'])->assertStatus(202);
        // PRC-08: the same answer for a known email (its link is sent again).
        $this->postJson("/api/v1/supplier-portal/notices/{$notice}/register", ['name' => 'Newco Ltd', 'email' => 'TENDERS@newco.example.com'])->assertStatus(202)->assertJsonPath('data.sent', true);
        $this->getJson('/api/v1/supplier-portal/notices/'.str_repeat('a', 48))->assertNotFound();
        // Decision 22: before the opening staff do not see who registered.
        $bidders = $this->api('GET', "procurement/tenders/{$tender['id']}")->json('data.bidders');
        $this->assertNull(collect($bidders)->firstWhere('name', 'Newco Ltd'));
        $this->inTenant(fn () => $this->assertSame('registered', Bidder::query()->whereRaw('lower(email) = ?', ['tenders@newco.example.com'])->value('source')));

        // An invited tender has no public notice.
        $invited = $this->publishedTender(['title' => 'Desks']);
        $this->assertNull($this->api('GET', "procurement/tenders/{$invited['id']}")->json('data.notice_url'));
    }

    public function test_the_technical_score_is_the_mean_of_complete_evaluations(): void
    {
        $criteria = collect([
            (object) ['id' => 'c1', 'weight' => 60, 'max_score' => 10],
            (object) ['id' => 'c2', 'weight' => 40, 'max_score' => 5],
        ]);
        $score = fn (string $evaluator, string $criterion, string $value) => (object) ['evaluator_id' => $evaluator, 'criterion_id' => $criterion, 'score' => $value];

        $all = collect([$score('e1', 'c1', '7'), $score('e1', 'c2', '3'), $score('e2', 'c1', '9'), $score('e2', 'c2', '5'), $score('e3', 'c1', '1')]);
        // e1: 7/10 × 60 + 3/5 × 40 = 66; e2: 9/10 × 60 + 5/5 × 40 = 94: the mean of the committee's two evaluators.
        $this->assertSame(8000, Evaluation::technical($criteria, $all, ['e1', 'e2']));
        // The same set for every bid: while a committee evaluator (e3) has not scored every criterion, there is no score.
        $this->assertNull(Evaluation::technical($criteria, $all, ['e1', 'e2', 'e3']));
        $this->assertNull(Evaluation::technical($criteria, $all, []));
        // A third of a point rounds half up once: 1/3 × 100 = 33.33.
        $this->assertSame(3333, Evaluation::technical(collect([(object) ['id' => 'c', 'weight' => 100, 'max_score' => 3]]), collect([$score('e', 'c', '1')]), ['e']));
    }

    public function test_cancelling_a_tender_ends_its_portal_sessions(): void
    {
        $tender = $this->publishedTender();
        [$upesi] = $this->bidders($tender);
        $this->getJson('/api/v1/supplier-portal/tender', $upesi)->assertOk();
        $this->api('POST', "procurement/tenders/{$tender['id']}/cancel", ['reason' => 'Budget cut'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson('/api/v1/supplier-portal/tender', $upesi)->assertUnauthorized()->assertJsonPath('code', 'portal_session_invalid');
        $this->inTenant(fn () => $this->assertSame(Tender::CANCELLED, Tender::query()->findOrFail($tender['id'])->status));
    }
}
