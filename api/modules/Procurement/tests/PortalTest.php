<?php

namespace Modules\Procurement\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\Parties\Party;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\PortalSession;
use Modules\Procurement\Tenders\Bidders;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PRC-07, PRC-08, PRC-23: the supplier portal's identity and isolation:
// a session reaches only its own tender and bid, never another bidder's
// or another tenant's, never with a user token; it ends with the bidder,
// the tender or the deadline; every route is rate-limited and audited.
class PortalTest extends TestCase
{
    use BuildsProcurement, RefreshTenantDatabase;

    private array $tender;

    private string $otherOpener = '';

    /** @var array<string, string> bidder id by email */
    private array $bidderIds;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $this->setUpProcurement();
        $this->tender = $this->tenderAt($this->owner, $this->branchA->id, [$this->supplier, $this->otherSupplier]);
        $this->bidderIds = collect($this->api('GET', "procurement/tenders/{$this->tender['id']}")->json('data.bidders'))->pluck('id', 'email')->all();
    }

    private function tenderAt($owner, string $branchId, array $suppliers, string $deadline = '2026-10-20T12:00:00Z', string $tenant = ''): array
    {
        $headers = $this->headersFor($owner);
        $tender = $this->postJson('/api/v1/procurement/tenders', [
            'branch_id' => $branchId, 'title' => 'Generators', 'kind' => 'invited', 'deadline_at' => $deadline, 'opening_at' => $deadline,
            'technical_weight' => 0, 'lines' => [['description' => 'Generator 10 kVA', 'qty' => '2']],
            'openers' => [$owner->id, $tenant === '' ? $this->manager->id : $tenant],
        ], $headers)->assertCreated()->json('data');

        foreach ($suppliers as $supplier) {
            $this->postJson("/api/v1/procurement/tenders/{$tender['id']}/bidders", ['party_id' => $supplier->id], $headers)->assertCreated();
        }

        return $this->postJson("/api/v1/procurement/tenders/{$tender['id']}/publish", [], $headers)->assertOk()->json('data');
    }

    private function submit(array $headers, string $price, array $files = []): void
    {
        $this->putJson('/api/v1/supplier-portal/bid', [
            'currency' => 'KES', 'lines' => [['tender_line_id' => $this->tender['lines'][0]['id'], 'unit_price_minor' => $price]], 'file_ids' => $files,
        ], $headers)->assertOk();
    }

    public function test_the_link_is_emailed_and_exchanged_for_a_session_of_one_tender_and_bidder(): void
    {
        // NOT-02, ADR 009: a system message to the supplier's address, the token only in the link.
        $this->inTenant(fn () => $this->assertSame(2, NotificationDelivery::query()->where('event_type', 'procurement.portal.invited')->count()));
        $upesi = $this->portal($this->linkFor($this->bidderIds['sales@upesi.example.com']));
        $view = $this->getJson('/api/v1/supplier-portal/tender', $upesi)->assertOk();

        $this->assertSame('Generators', $view->json('data.tender.title'));
        $this->assertSame('Upesi Supplies', $view->json('data.bidder.name'));
        $this->assertNull($view->json('data.bid'));
        // Nothing of the other bidder, not even that it exists.
        $this->assertStringNotContainsString('Baraka', $view->getContent());
        $this->assertStringNotContainsString('baraka', $view->getContent());
        $this->assertArrayNotHasKey('bidders', $view->json('data.tender'));

        // Sign out ends the session.
        $this->deleteJson('/api/v1/supplier-portal/sessions/current', [], $upesi)->assertNoContent();
        $this->getJson('/api/v1/supplier-portal/tender', $upesi)->assertUnauthorized();
        $this->inTenant(fn () => $this->assertTrue(AuditEntry::query()->where('action', 'procurement.portal.session_start')->exists()));
    }

    public function test_a_session_never_reaches_another_bidders_bid_or_files(): void
    {
        $upesi = $this->portal($this->linkFor($this->bidderIds['sales@upesi.example.com']));
        $baraka = $this->portal($this->linkFor($this->bidderIds['bids@baraka.example.com']));
        $file = $this->postJson('/api/v1/supplier-portal/bid/files', ['file' => UploadedFile::fake()->createWithContent('quote.pdf', '%PDF-1.4 upesi')], $upesi)->assertCreated()->json('data.id');
        $this->submit($upesi, '900000', [$file]);

        // Baraka sees no bid of its own and cannot attach Upesi's file.
        $this->assertNull($this->getJson('/api/v1/supplier-portal/tender', $baraka)->assertOk()->json('data.bid'));
        $this->assertSame([], $this->getJson('/api/v1/supplier-portal/tender', $baraka)->json('data.files'));
        $this->putJson('/api/v1/supplier-portal/bid', [
            'currency' => 'KES', 'lines' => [['tender_line_id' => $this->tender['lines'][0]['id'], 'unit_price_minor' => '1']], 'file_ids' => [$file],
        ], $baraka)->assertUnprocessable()->assertJsonPath('code', 'file_unknown');
        // Nor withdraw Upesi's bid: withdrawing acts on its own (none).
        $this->postJson('/api/v1/supplier-portal/bid/withdraw', [], $baraka)->assertStatus(409)->assertJsonPath('code', 'no_bid');
        $this->assertSame('submitted', $this->getJson('/api/v1/supplier-portal/tender', $upesi)->json('data.bid.status'));
        // Lines of another tender are refused.
        $other = $this->tenderAt($this->owner, $this->branchA->id, []);
        $this->putJson('/api/v1/supplier-portal/bid', [
            'currency' => 'KES', 'lines' => [['tender_line_id' => $other['lines'][0]['id'], 'unit_price_minor' => '1']],
        ], $baraka)->assertUnprocessable()->assertJsonPath('code', 'line_unknown');
    }

    public function test_user_tokens_are_refused_on_the_portal_and_portal_tokens_in_the_back_office(): void
    {
        $upesi = $this->portal($this->linkFor($this->bidderIds['sales@upesi.example.com']));

        $this->getJson('/api/v1/supplier-portal/tender', $this->headersFor())->assertUnauthorized()->assertJsonPath('code', 'portal_session_invalid');
        $this->getJson('/api/v1/procurement/tenders', $upesi)->assertUnauthorized();
        $this->getJson("/api/v1/procurement/tenders/{$this->tender['id']}", $upesi)->assertUnauthorized();
        $this->postJson('/api/v1/supplier-portal/sessions', ['token' => str_repeat('x', 48)])->assertUnauthorized()->assertJsonPath('code', 'portal_link_invalid');
    }

    public function test_another_tenants_portal_sees_only_its_own_tenant(): void
    {
        $other = $this->otherTenant();
        $theirs = $this->asTenant($other['user']->tenant_id, function () use ($other) {
            app(ModuleRegistry::class)->activate('procurement');
            app(TenantCurrencies::class)->provisionFor($other['company']);

            $second = $this->colleague($other['user']);
            $this->assign($second, Role::query()->where('template_key', 'branch_manager')->sole(), Scope::branch($other['branch']->id));
            $this->otherOpener = $second->id;

            return Party::create(['kind' => 'organisation', 'name' => 'Other tenant supplier', 'roles' => ['supplier'],
                'emails' => [['address' => 'other@supplier.example.com', 'label' => null]]]);
        });
        $theirTender = $this->tenderAt($other['user'], $other['branch']->id, [$theirs], tenant: $this->otherOpener);
        $theirBidder = $this->getJson("/api/v1/procurement/tenders/{$theirTender['id']}", $this->headersFor($other['user']))->json('data.bidders.0.id');
        $theirLink = $this->asTenant($other['user']->tenant_id, fn () => app(Bidders::class)->issueLinkForTesting(Bidder::query()->findOrFail($theirBidder)));
        $theirs = $this->portal($theirLink);
        app(TenantContext::class)->set(null);

        $view = $this->getJson('/api/v1/supplier-portal/tender', $theirs)->assertOk();
        $this->assertSame('Other tenant supplier', $view->json('data.bidder.name'));
        $this->assertStringNotContainsString($this->tender['id'], $view->getContent());
        $this->assertStringNotContainsString('Upesi', $view->getContent());
        // Tenant A's tender line is not theirs to bid on.
        $this->putJson('/api/v1/supplier-portal/bid', [
            'currency' => 'KES', 'lines' => [['tender_line_id' => $this->tender['lines'][0]['id'], 'unit_price_minor' => '1']],
        ], $theirs)->assertUnprocessable()->assertJsonPath('code', 'line_unknown');
        // And tenant A's tender gets no bid from it.
        $this->assertSame(0, $this->inTenant(fn () => Bid::query()->count()));
    }

    public function test_a_removed_bidder_and_an_inactive_module_end_the_session(): void
    {
        $upesi = $this->portal($this->linkFor($this->bidderIds['sales@upesi.example.com']));
        $baraka = $this->portal($this->linkFor($this->bidderIds['bids@baraka.example.com']));
        $this->submit($upesi, '1000');
        $this->api('POST', "procurement/tender-bidders/{$this->bidderIds['sales@upesi.example.com']}/remove")->assertOk();

        $this->getJson('/api/v1/supplier-portal/tender', $upesi)->assertUnauthorized();
        $this->assertSame('withdrawn', $this->api('GET', "procurement/tenders/{$this->tender['id']}")->json('data.bids.0.status'));
        $this->inTenant(fn () => $this->assertNotNull(PortalSession::query()->where('bidder_id', $this->bidderIds['sales@upesi.example.com'])->first()->revoked_at));

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('procurement'));
        $this->getJson('/api/v1/supplier-portal/tender', $baraka)->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }

    public function test_after_the_deadline_the_portal_is_read_only(): void
    {
        $short = $this->tenderAt($this->owner, $this->branchA->id, [$this->supplier], deadline: '2026-10-15T10:00:00Z');
        $bidder = $this->api('GET', "procurement/tenders/{$short['id']}")->json('data.bidders.0.id');
        $headers = $this->portal($this->linkFor($bidder));
        $this->clockTo('2026-10-15T10:00:01Z');

        $this->putJson('/api/v1/supplier-portal/bid', [
            'currency' => 'KES', 'lines' => [['tender_line_id' => $short['lines'][0]['id'], 'unit_price_minor' => '1']],
        ], $headers)->assertStatus(409)->assertJsonPath('code', 'deadline_passed');
        $this->postJson('/api/v1/supplier-portal/bid/files', ['file' => UploadedFile::fake()->createWithContent('late.txt', 'late')], $headers)->assertStatus(409);
        // PRC-07: the link still opens a session, to read the tender and, later, its outcome.
        $later = $this->portal($this->linkFor($bidder));
        $this->getJson('/api/v1/supplier-portal/tender', $later)->assertOk()->assertJsonPath('data.tender.open_for_bids', false);
    }

    public function test_uploads_are_typed_and_limited_and_sign_in_is_rate_limited(): void
    {
        $upesi = $this->portal($this->linkFor($this->bidderIds['sales@upesi.example.com']));
        $this->postJson('/api/v1/supplier-portal/bid/files', ['file' => UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload')], $upesi)
            ->assertUnprocessable()->assertJsonPath('code', 'file_type');

        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/v1/supplier-portal/sessions', ['token' => str_repeat('y', 48)]);
        }

        $this->postJson('/api/v1/supplier-portal/sessions', ['token' => str_repeat('y', 48)])->assertStatus(429);
    }
}
