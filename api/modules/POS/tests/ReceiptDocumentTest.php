<?php

namespace Modules\POS\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\DocumentTemplates\DefaultTemplates;
use App\Core\DocumentTemplates\DocumentEmails;
use App\Core\DocumentTemplates\Jobs\SendDocumentEmail;
use App\Core\DocumentTemplates\Mail\DocumentMail;
use App\Core\DocumentTemplates\Models\DocumentShare;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\POS\Models\Shift;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Templates\TemplateText;
use Tests\TestCase;

/**
 * TPL-03, TPL-04, TPL-05, POS-06, TEN-01, AUD-01: a sale's receipt from
 * the back office, printed with the template that applies: HTML and PDF,
 * email (queued, rate-limited, audited), the public link (created,
 * opened, expired, revoked), other tenants, permissions, and the receipt
 * templates a till syncs.
 */
class ReceiptDocumentTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private string $saleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPos();
        $customer = $this->inTenant(fn () => Party::create([
            'kind' => 'person', 'name' => 'Amina <VIP>', 'roles' => ['customer'], 'tags' => ['vip'], 'emails' => [['address' => 'amina@example.com', 'label' => 'main']],
        ]));
        $this->ranges()->assertOk();
        $sale = $this->saleBody($this->openShift(), 1, ['customer_id' => $customer->id]);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->saleId = $sale['id'];
    }

    private function url(string $suffix): string
    {
        return "/api/v1/pos/sales/{$this->saleId}/{$suffix}";
    }

    public function test_the_receipt_prints_as_html_and_downloads_as_a_pdf(): void
    {
        $html = $this->get($this->url('receipt'), $this->headersFor())->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')->getContent();
        $text = TemplateText::of($html);

        // The default template: what the till printed before templates, the fiscal block locked on (TPL-03).
        foreach (['Acme', 'Receipt R-L01-000001', 'Customer Amina <VIP>', 'Soap', '2 × KES 562.50 KES 1,125.00', 'VAT test 12.5% KES 125.00', 'Total KES 1,125.00', 'Cash KES KES 1,125.00', 'KRA eTIMS'] as $line) {
            $this->assertStringContainsString($line, $text);
        }
        $this->assertStringContainsString('Amina &lt;VIP&gt;', $html);
        $this->assertStringNotContainsString('var(--', $html);

        $pdf = $this->get($this->url('receipt?format=pdf'), $this->headersFor())->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('pos-receipt-R-L01-000001.pdf', $pdf->headers->get('Content-Disposition'));
        $this->getJson($this->url('receipt?format=docx'), $this->headersFor())->assertUnprocessable();

        // TPL-05: a published branch template with a variant for VIP customers prints instead.
        $payload = DefaultTemplates::for('pos.receipt');
        $payload['variants'] = [['id' => 'vip', 'name' => 'VIP', 'applies_when' => ['customer_tags' => ['vip']], 'language' => 'fr',
            'blocks' => [['id' => 'thanks', 'type' => 'text', 'text' => 'Asante {{customer.name}}'], ...$payload['blocks']]]];
        $created = $this->postJson('/api/v1/config/template', ['key' => 'pos.receipt', 'scope_type' => 'branch', 'scope_id' => $this->branchA->id, 'payload' => $payload], $this->headersFor())->assertCreated();
        $id = $created->json('data.id');
        $this->postJson("/api/v1/config/template/{$id}/publish", ['revision' => $created->json('data.draft.revision')], $this->headersFor())->assertOk();

        $text = TemplateText::of($this->get($this->url('receipt'), $this->headersFor())->assertOk()->getContent());
        $this->assertStringContainsString('Asante Amina <VIP>', $text);
        $this->assertStringContainsString('Reçu R-L01-000001', $text);
    }

    public function test_the_receipt_is_emailed_from_a_queue_rate_limited_and_audited(): void
    {
        Bus::fake([SendDocumentEmail::class]);

        // To the customer's address when none is typed; to the typed one otherwise.
        $this->postJson($this->url('email'), [], $this->headersFor())->assertStatus(202)->assertJsonPath('data.to', 'amina@example.com');
        $this->postJson($this->url('email'), ['email' => 'books@example.com', 'language' => 'fr'], $this->headersFor())->assertStatus(202);
        $this->postJson($this->url('email'), ['email' => 'not-an-address'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('email');

        Bus::assertDispatchedTimes(SendDocumentEmail::class, 2);
        Bus::assertDispatched(SendDocumentEmail::class, fn (SendDocumentEmail $job) => $job->email === 'books@example.com' && $job->locale === 'fr'
            && $job->tenantId === $this->owner->tenant_id && $job->recordId === $this->saleId && $job->type === 'pos.receipt');

        $audit = $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.document.email')->where('auditable_id', $this->saleId)->orderBy('seq')->get());
        $this->assertSame(['amina@example.com', 'books@example.com'], $audit->map(fn ($entry) => $entry->after['to'])->all());
        $this->assertSame($this->owner->id, $audit[0]->user_id);

        // TPL-04: at most PER_MINUTE a minute per user.
        for ($i = 2; $i < DocumentEmails::PER_MINUTE; $i++) {
            $this->postJson($this->url('email'), [], $this->headersFor())->assertStatus(202);
        }
        $this->postJson($this->url('email'), [], $this->headersFor())->assertStatus(429)->assertJsonPath('code', 'too_many_emails');
        Bus::assertDispatchedTimes(SendDocumentEmail::class, DocumentEmails::PER_MINUTE);
    }

    public function test_the_email_job_sends_the_pdf_in_its_tenant_and_a_sale_without_an_address_is_refused(): void
    {
        Mail::fake();
        app(TenantContext::class)->set(null);

        dispatch_sync(new SendDocumentEmail($this->owner->tenant_id, 'pos.receipt', $this->saleId, 'amina@example.com', 'fr'));

        Mail::assertSent(DocumentMail::class, function (DocumentMail $mail) {
            return $mail->hasTo('amina@example.com')
                && $mail->mailSubject === 'Reçu de caisse R-L01-000001 de Acme'
                && str_starts_with($mail->pdf, '%PDF')
                && $mail->fileName === 'pos-receipt-R-L01-000001.pdf'
                && count($mail->attachments()) === 1;
        });

        // A record of another tenant is not found by the job (row-level security): nothing is sent.
        $other = $this->otherTenant();
        dispatch_sync(new SendDocumentEmail($other['user']->tenant_id, 'pos.receipt', $this->saleId, 'thief@example.com', 'en'));
        Mail::assertSentCount(1);

        // No address typed and none on the customer: refused, with what to do.
        $walkIn = $this->saleBody($this->inTenant(fn () => Shift::query()->value('id')), 2);
        $this->upload([$walkIn])->assertOk();
        $this->postJson("/api/v1/pos/sales/{$walkIn['id']}/email", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'no_email');
    }

    public function test_a_share_link_is_created_opened_revoked_and_expires(): void
    {
        $share = $this->postJson($this->url('share'), [], $this->headersFor())->assertCreated()->json('data');
        $token = basename(parse_url($share['url'], PHP_URL_PATH));

        // Random, 48 characters, no ids in the URL, stored hashed; WhatsApp opens with the link.
        $this->assertMatchesRegularExpression('#/d/[A-Za-z0-9]{48}$#', $share['url']);
        $this->assertStringNotContainsString($this->saleId, $share['url']);
        $this->assertSame('https://wa.me/?text='.rawurlencode($share['url']), $share['whatsapp_url']);
        $row = $this->inTenant(fn () => DocumentShare::query()->findOrFail($share['id']));
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $row->expires_at->timestamp, 5);

        // Anyone with the link gets the PDF, with no session; the open is counted and audited.
        app(TenantContext::class)->set(null);
        $pdf = $this->get("/d/{$token}")->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame(1, $this->inTenant(fn () => DocumentShare::query()->findOrFail($share['id'])->access_count));
        $opened = $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.document.share_open')->sole());
        $this->assertSame([$share['id'], null], [$opened->auditable_id, $opened->user_id]);
        $this->assertStringNotContainsString($token, json_encode($this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $share['id'])->get()->toArray())));

        // Unknown and malformed tokens: not found, nothing confirmed.
        app(TenantContext::class)->set(null);
        $this->get('/d/'.str_repeat('a', 48))->assertNotFound();
        $this->get('/d/short')->assertNotFound();

        // The list shows it; revoking stops the link (410), and is audited.
        $this->getJson($this->url('shares'), $this->headersFor())->assertOk()->assertJsonPath('data.0.id', $share['id'])->assertJsonPath('data.0.access_count', 1)->assertJsonPath('data.0.status', 'active');
        $this->postJson($this->url("shares/{$share['id']}/revoke"), [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'revoked');
        app(TenantContext::class)->set(null);
        $this->get("/d/{$token}")->assertStatus(410)->assertSee('This link has expired or was withdrawn');
        $this->assertSame(['core.document.share_create', 'core.document.share_open', 'core.document.share_revoke'],
            $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $share['id'])->orderBy('seq')->pluck('action')->all()));

        // A second link expires after 7 days.
        $second = $this->postJson($this->url('share'), [], $this->headersFor())->assertCreated()->json('data');
        $this->travel(7)->days();
        $this->travel(1)->minutes();
        app(TenantContext::class)->set(null);
        $this->get('/d/'.basename(parse_url($second['url'], PHP_URL_PATH)))->assertStatus(410);
        $this->assertSame(0, $this->inTenant(fn () => DocumentShare::query()->findOrFail($second['id'])->access_count));
    }

    public function test_a_link_stops_when_the_module_is_off_or_the_tenant_is_not_active(): void
    {
        $url = $this->postJson($this->url('share'), [], $this->headersFor())->assertCreated()->json('data.url');
        $path = parse_url($url, PHP_URL_PATH);

        // RBAC-08: POS switched off for the tenant.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('pos'));
        app(TenantContext::class)->set(null);
        $this->get($path)->assertStatus(410)->assertSee('This link has expired or was withdrawn');
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('pos'));
        app(TenantContext::class)->set(null);
        $this->get($path)->assertOk();

        // TEN-01: a suspended tenant shares nothing.
        $this->inTenant(fn () => Tenant::query()->whereKey($this->owner->tenant_id)->update(['status' => 'suspended']));
        app(TenantContext::class)->set(null);
        $this->get($path)->assertStatus(410);
    }

    public function test_another_tenant_reaches_nothing_of_the_sale_or_its_shares(): void
    {
        $share = $this->postJson($this->url('share'), [], $this->headersFor())->assertCreated()->json('data');
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('pos'));
        $headers = $this->headersFor($other['user']);

        $this->getJson($this->url('receipt'), $headers)->assertNotFound();
        $this->postJson($this->url('email'), ['email' => 'x@example.com'], $headers)->assertNotFound();
        $this->postJson($this->url('share'), [], $headers)->assertNotFound();
        $this->getJson($this->url('shares'), $headers)->assertNotFound();
        $this->postJson($this->url("shares/{$share['id']}/revoke"), [], $headers)->assertNotFound();

        // Row-level security: tenant B sees no share of A, and the lookup function returns only a tenant id.
        $this->assertSame(0, $this->asTenant($other['user']->tenant_id, fn () => DocumentShare::query()->count()));
        $this->assertSame($this->owner->tenant_id, DB::selectOne('select document_share_tenant(?) as id', [$this->inTenant(fn () => DocumentShare::query()->sole()->token_hash)])->id);
        $this->assertSame('active', $this->inTenant(fn () => DocumentShare::query()->sole()->status()));
    }

    public function test_viewing_prints_and_sharing_needs_its_own_permission(): void
    {
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $elsewhere = $this->userWith('cashier', Scope::location($this->locationB->id));
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $admin = $this->userWith('admin', Scope::tenant());
        $share = $this->postJson($this->url('share'), [], $this->headersFor())->assertCreated()->json('data.id');
        Bus::fake([SendDocumentEmail::class]);

        // pos.sale.view prints and downloads; email and links need pos.sale.share.
        $this->get($this->url('receipt'), $this->headersFor($cashier))->assertOk();
        $this->postJson($this->url('email'), [], $this->headersFor($cashier))->assertForbidden();
        $this->postJson($this->url('share'), [], $this->headersFor($cashier))->assertForbidden();
        $this->getJson($this->url('shares'), $this->headersFor($cashier))->assertForbidden();
        $this->postJson($this->url("shares/{$share}/revoke"), [], $this->headersFor($cashier))->assertForbidden();

        // Out of scope: the sale is not found.
        $this->getJson($this->url('receipt'), $this->headersFor($elsewhere))->assertNotFound();
        $this->postJson($this->url('share'), [], $this->headersFor($elsewhere))->assertNotFound();

        // Owner, Admin and Branch Manager hold pos.sale.share (the Admin template has no
        // pos.sale.view, so an admin reaches sales only with another role that sees them).
        $this->postJson($this->url('share'), [], $this->headersFor($manager))->assertCreated();
        $this->postJson($this->url('email'), [], $this->headersFor($manager))->assertStatus(202);
        $this->assertTrue($this->inTenant(fn () => app(ScopeResolver::class)->can($admin, 'pos.sale.share', Scope::tenant())));

        // A share of another sale is not this sale's.
        $second = $this->saleBody($this->inTenant(fn () => Shift::query()->value('id')), 2);
        $this->upload([$second])->assertOk();
        $this->postJson("/api/v1/pos/sales/{$second['id']}/shares/{$share}/revoke", [], $this->headersFor())->assertNotFound();
    }

    public function test_the_till_syncs_the_receipt_templates_of_its_branch(): void
    {
        $pull = fn () => collect($this->getJson('/api/v1/sync/pull?entities[]=templates', $this->tillHeaders())->assertOk()->json('entities.templates.upserts'))->keyBy('id');

        $rows = $pull();
        $this->assertSame(['pos.receipt', 'pos.refund_receipt'], $rows->keys()->all());
        // Nothing published: the default receipt, the fiscal block required in Kenya, the wording in both languages.
        $this->assertSame([DefaultTemplates::for('pos.receipt')['blocks'], null, ['required' => true, 'authority' => 'kra_etims']],
            [$rows['pos.receipt']['payload']['blocks'], $rows['pos.receipt']['source'], $rows['pos.receipt']['fiscal']]);
        $this->assertSame(['Receipt', 'Reçu'], [$rows['pos.receipt']['labels']['en']['numbers']['pos_receipt'], $rows['pos.receipt']['labels']['fr']['numbers']['pos_receipt']]);

        // A template published for the company reaches the till, with its variants.
        $payload = [...DefaultTemplates::for('pos.receipt'), 'paper' => '58mm', 'variants' => [['id' => 'vip', 'name' => 'VIP', 'applies_when' => ['customer_tags' => ['vip']], 'blocks' => DefaultTemplates::for('pos.receipt')['blocks']]]];
        $created = $this->postJson('/api/v1/config/template', ['key' => 'pos.receipt', 'scope_type' => 'company', 'scope_id' => $this->acme->id, 'payload' => $payload], $this->headersFor())->assertCreated();
        $id = $created->json('data.id');
        $this->postJson("/api/v1/config/template/{$id}/publish", ['revision' => $created->json('data.draft.revision')], $this->headersFor())->assertOk();
        $this->travel(2)->minutes();

        $row = $pull()['pos.receipt'];
        $this->assertSame(['58mm', 'vip', $id, 1], [$row['payload']['paper'], $row['payload']['variants'][0]['id'], $row['source']['document_id'], $row['source']['version']]);
    }
}
