<?php

namespace Tests\Feature\Core\DocumentTemplates;

use App\Core\Audit\AuditEntry;
use App\Core\DocumentTemplates\DataSources;
use App\Core\DocumentTemplates\DefaultTemplates;
use App\Core\DocumentTemplates\TemplateResolver;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * TPL-01..TPL-03, TPL-05, CP-01, TEN-01, AUD-01: document templates as
 * versioned configuration (`config/template`): the fiscal block required
 * and locked where the country pack says so (KE eTIMS, CD DGI), the other
 * validation rules, resolution by branch, company and tenant with
 * variants, the designer's types and previews, permissions and other
 * tenants.
 */
class TemplateApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private const URL = '/api/v1/config/template';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganisation();
    }

    private function receipt(array $overrides = []): array
    {
        return [...DefaultTemplates::for('pos.receipt'), ...$overrides];
    }

    /** Saves a draft for $type at the scope (over the current draft revision, LAY-06) and returns the document's response. */
    private function save(string $type, array $payload, string $scopeType = 'company', ?string $scopeId = null, ?array $headers = null, int $status = 201): array
    {
        $scopeId = $scopeType === 'tenant' ? null : ($scopeId ?? $this->acme->id);
        $existing = collect($this->getJson(self::URL.'?key='.$type.'&per_page=200', $this->headersFor())->json('data'))
            ->first(fn (array $d) => $d['scope']['type'] === $scopeType && $d['scope']['id'] === $scopeId);
        $revision = $existing === null ? null : $this->getJson(self::URL.'/'.$existing['id'], $this->headersFor())->json('data.draft.revision');

        return $this->postJson(self::URL, [
            'key' => $type, 'scope_type' => $scopeType, 'scope_id' => $scopeId, 'payload' => $payload, 'revision' => $revision,
        ], $headers ?? $this->headersFor())->assertStatus($status)->json();
    }

    private function publish(string $id): TestResponse
    {
        $revision = $this->getJson(self::URL.'/'.$id, $this->headersFor())->json('data.draft.revision');

        return $this->postJson(self::URL."/{$id}/publish", ['revision' => $revision], $this->headersFor());
    }

    private function withoutFiscal(array $payload): array
    {
        return [...$payload, 'blocks' => array_values(array_filter($payload['blocks'], fn (array $b) => $b['type'] !== 'fiscal'))];
    }

    public function test_the_fiscal_block_is_required_where_the_country_pack_requires_it(): void
    {
        // TPL-03, CP-01: Kenya (KRA eTIMS) needs it on receipts.
        $draft = $this->save('pos.receipt', $this->withoutFiscal($this->receipt()));
        $this->assertSame(['blocks' => 'fiscal_required'], collect($draft['meta']['problems'])->mapWithKeys(fn ($p) => [$p['path'] => $p['code']])->all());
        $this->publish($draft['data']['id'])->assertUnprocessable()->assertJsonPath('code', 'config_invalid')->assertJsonPath('problems.0.code', 'fiscal_required');

        // The DR Congo (DGI) too, at a branch of a CD company.
        $congo = $this->inTenant(fn () => tap($this->company('Congo'), fn (Company $c) => $c->forceFill(['country' => 'CD'])->save()));
        $gombe = $this->inTenant(fn () => $this->branch(Company::findOrFail($congo->id), 'G'));
        $this->assertSame('fiscal_required', $this->save('pos.receipt', $this->withoutFiscal($this->receipt()), 'branch', $gombe->id)['meta']['problems'][0]['code']);

        // A tenant-wide template prints for every company, so it needs it too.
        $this->assertSame('fiscal_required', $this->save('pos.refund_receipt', $this->withoutFiscal(DefaultTemplates::for('pos.refund_receipt')), 'tenant')['meta']['problems'][0]['code']);

        // In every variant as well, with the totals (their tax lines are locked on too).
        $variant = ['id' => 'vip', 'name' => 'VIP', 'applies_when' => ['customer_tags' => ['vip']], 'blocks' => [['id' => 't', 'type' => 'text', 'text' => 'VIP']]];
        $problems = $this->save('pos.receipt', $this->receipt(['variants' => [$variant]]), status: 200)['meta']['problems'];
        $this->assertSame([['variants.0.blocks', 'fiscal_required'], ['variants.0.blocks', 'totals_required']], array_map(fn ($p) => [$p['path'], $p['code']], $problems));

        // TPL-03: without the totals block the tax lines would not print: refused.
        $noTotals = $this->receipt();
        $noTotals['blocks'] = array_values(array_filter($noTotals['blocks'], fn (array $b) => $b['type'] !== 'totals'));
        $this->assertSame(['blocks:totals_required'], array_map(fn ($p) => $p['path'].':'.$p['code'], $this->save('pos.receipt', $noTotals, status: 200)['meta']['problems']));

        // With the block, it publishes; a quote never carries one.
        $ok = $this->save('pos.receipt', $this->receipt(), status: 200);
        $this->assertSame([], $ok['meta']['problems']);
        $this->publish($ok['data']['id'])->assertOk()->assertJsonPath('data.published.version', 1);
        $quote = $this->save('sales.quote', DefaultTemplates::for('sales.quote'));
        $this->assertSame([], $quote['meta']['problems']);
        $withFiscal = DefaultTemplates::for('sales.quote');
        $withFiscal['blocks'][] = ['id' => 'fiscal', 'type' => 'fiscal'];
        $this->assertSame('fiscal_not_allowed', $this->save('sales.quote', $withFiscal, status: 200)['meta']['problems'][0]['code']);

        // AUD-01: the template's history is audited like any configuration.
        $this->assertContains('core.config.publish', $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $ok['data']['id'])->pluck('action')->all()));
    }

    public function test_tax_lines_rows_columns_fields_and_blocks_are_checked(): void
    {
        $payload = $this->receipt();
        $payload['blocks'][] = ['id' => 'fiscal-2', 'type' => 'fiscal'];
        $payload['blocks'][] = ['id' => 'row', 'type' => 'row', 'columns' => [[], []]];
        $payload['blocks'][] = ['id' => 'tl', 'type' => 'totals', 'tax_lines' => false];
        $payload['blocks'][] = ['id' => 'f', 'type' => 'field', 'field' => 'customer.shoe_size'];
        $payload['blocks'][] = ['id' => 'l', 'type' => 'lines', 'columns' => ['item_name', 'colour']];
        $payload['blocks'][] = ['id' => 'x', 'type' => 'text', 'text' => 'Hi {{customer.nickname}}'];
        $payload['blocks'][] = ['id' => 'x', 'type' => 'carousel'];
        $payload['blocks'][] = ['id' => 'q', 'type' => 'qr'];

        $codes = array_map(fn ($p) => $p['path'].':'.$p['code'], $this->save('pos.receipt', $payload)['meta']['problems']);

        $this->assertEqualsCanonicalizing([
            'blocks.15:fiscal_twice',
            'blocks.16:row_on_thermal',
            'blocks.17.tax_lines:tax_lines_locked',
            'blocks.18.field:unknown_field',
            'blocks.19.columns.1:unknown_column',
            'blocks.20:unknown_field',
            'blocks.21.type:unknown_block',
            'blocks.22.content:required',
        ], $codes);

        // A4 allows a two-column row; rows don't nest.
        $invoice = DefaultTemplates::for('sales.invoice');
        $this->assertSame([], $this->save('sales.invoice', $invoice)['meta']['problems']);
        $invoice['blocks'][0]['columns'][0][] = ['id' => 'inner', 'type' => 'row', 'columns' => [[], []]];
        $this->assertSame('row_nested', $this->save('sales.invoice', $invoice, status: 200)['meta']['problems'][0]['code']);

        // Unknown document types are not keys of the kind.
        $this->postJson(self::URL, ['key' => 'pos.coupon', 'scope_type' => 'tenant', 'payload' => $payload], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('key');
        // Locations are not a template scope.
        $this->postJson(self::URL, ['key' => 'pos.receipt', 'scope_type' => 'location', 'scope_id' => $this->locationA->id, 'payload' => $payload], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('scope_type');
    }

    public function test_resolution_takes_the_most_specific_scope_then_the_first_matching_variant(): void
    {
        $text = fn (string $id, string $text) => ['id' => $id, 'type' => 'text', 'text' => $text];
        $publish = function (string $scope, ?string $id, array $payload) {
            $this->publish($this->save('pos.receipt', $payload, $scope, $id)['data']['id'])->assertOk();
        };

        $publish('tenant', null, $this->receipt(['blocks' => [$text('t', 'Tenant'), ['id' => 'totals', 'type' => 'totals', 'show' => ['total']], ['id' => 'fiscal', 'type' => 'fiscal']]]));
        $publish('branch', $this->branchA->id, $this->receipt([
            'blocks' => [$text('t', 'Branch A'), ['id' => 'totals', 'type' => 'totals', 'show' => ['total']], ['id' => 'fiscal', 'type' => 'fiscal']],
            'variants' => [
                ['id' => 'vip', 'name' => 'VIP', 'applies_when' => ['customer_tags' => ['VIP']], 'blocks' => [$text('t', 'VIP'), ['id' => 'totals', 'type' => 'totals', 'show' => ['total']], ['id' => 'fiscal', 'type' => 'fiscal']]],
                ['id' => 'big', 'name' => 'Big sale', 'applies_when' => ['conditions' => [['field' => 'totals.total', 'op' => 'gte', 'value' => '10000.00']]], 'language' => 'fr', 'blocks' => [$text('t', 'Big'), ['id' => 'totals', 'type' => 'totals', 'show' => ['total']], ['id' => 'fiscal', 'type' => 'fiscal']]],
            ],
        ]));

        $resolve = fn (?string $branch, array $data = []) => $this->inTenant(fn () => app(TemplateResolver::class)->resolve('pos.receipt', $this->acme->id, $branch, [
            'currencies' => ['KES' => 2], 'customer' => null, 'totals' => ['total' => ['minor' => '50000', 'currency' => 'KES']], ...$data,
        ]));

        // Branch B has none of its own: the tenant's. Branch A: its own.
        $this->assertSame(['Tenant', 'tenant', null], [$resolve($this->branchB->id)['template']['blocks'][0]['text'], $resolve($this->branchB->id)['source']['scope']['type'], $resolve($this->branchB->id)['source']['variant']]);
        $this->assertSame('Branch A', $resolve($this->branchA->id)['template']['blocks'][0]['text']);
        $this->assertArrayNotHasKey('variants', $resolve($this->branchA->id)['template']);

        // TPL-05: a customer tagged vip (any case) gets the first variant; a big sale the second.
        $vip = $resolve($this->branchA->id, ['customer' => ['name' => 'Amina', 'tags' => ['vip']]]);
        $this->assertSame(['VIP', 'vip'], [$vip['template']['blocks'][0]['text'], $vip['source']['variant']]);
        $big = $resolve($this->branchA->id, ['totals' => ['total' => ['minor' => '1000000', 'currency' => 'KES']]]);
        $this->assertSame(['Big', 'fr', 'big'], [$big['template']['blocks'][0]['text'], $big['template']['language'], $big['source']['variant']]);
        // Both match: the first one listed wins.
        $both = $resolve($this->branchA->id, ['customer' => ['tags' => ['VIP']], 'totals' => ['total' => ['minor' => '1000000', 'currency' => 'KES']]]);
        $this->assertSame('vip', $both['source']['variant']);

        // Drafts never apply; with nothing published, the default receipt.
        $this->save('pos.receipt', $this->receipt(['blocks' => [$text('t', 'Draft'), ['id' => 'totals', 'type' => 'totals', 'show' => ['total']], ['id' => 'fiscal', 'type' => 'fiscal']]]), 'branch', $this->branchB->id);
        $this->assertSame('Tenant', $resolve($this->branchB->id)['template']['blocks'][0]['text']);
        $default = $this->inTenant(fn () => app(TemplateResolver::class)->resolve('pos.refund_receipt', $this->acme->id, null, []));
        $this->assertSame([DefaultTemplates::for('pos.refund_receipt')['blocks'], null], [$default['template']['blocks'], $default['source']]);
    }

    public function test_the_designer_gets_types_fields_locked_blocks_and_custom_fields(): void
    {
        // CF-01 hook: one line adds an entity's custom fields to the merge fields.
        app(DataSources::class)->customFields('customer', fn () => [['key' => 'loyalty_no', 'label' => 'Loyalty no.', 'type' => 'text']]);

        $types = $this->getJson('/api/v1/templates/types?scope_type=company&scope_id='.$this->acme->id, $this->headersFor())->assertOk()->json();
        $receipt = collect($types['data'])->firstWhere('key', 'pos.receipt');
        $quote = collect($types['data'])->firstWhere('key', 'sales.quote');

        $this->assertSame(['pos.receipt', 'pos.refund_receipt', 'sales.invoice', 'sales.quote', 'procurement.po', 'stores.delivery_note', 'payroll.payslip', 'party.statement', 'letter'], array_column($types['data'], 'key'));
        $this->assertSame([true, true, 'kra_etims', ['fiscal', 'totals']], [$receipt['live'], $receipt['fiscal']['required'], $receipt['fiscal']['authority'], $receipt['locked']]);
        $this->assertSame([false, false, []], [$quote['live'], $quote['fiscal']['required'], $quote['locked']]);
        $this->assertSame('80mm', $receipt['default']['paper']);
        $this->assertContains(['path' => 'customer.custom.loyalty_no', 'group' => 'custom', 'type' => 'text', 'label' => 'Loyalty no.'], $receipt['fields']);
        $this->assertContains('Receipt', array_column($receipt['fields'], 'label'));
        $this->assertSame('KE', $types['meta']['country']);

        // A preview prints the custom field's label as typed and the value from the data.
        $payload = $this->receipt(['blocks' => [['id' => 'l', 'type' => 'field', 'field' => 'customer.custom.loyalty_no', 'label' => true], ['id' => 'totals', 'type' => 'totals', 'show' => ['total']], ['id' => 'fiscal', 'type' => 'fiscal']]]);
        $preview = $this->postJson('/api/v1/templates/preview', ['type' => 'pos.receipt', 'payload' => $payload], $this->headersFor())->assertOk()->json('data');
        $this->assertSame([], $preview['problems']);
        $this->assertStringContainsString('KRA eTIMS', $preview['html']);
    }

    public function test_the_preview_renders_sample_data_as_html_and_a_pdf_for_its_author_only(): void
    {
        $payload = $this->withoutFiscal($this->receipt(['language' => 'both']));
        $preview = $this->postJson('/api/v1/templates/preview', ['type' => 'pos.receipt', 'payload' => $payload, 'scope_type' => 'branch', 'scope_id' => $this->branchA->id], $this->headersFor())
            ->assertOk()->json('data');

        // TPL-03: the fiscal block is printed even when the draft lacks it, and the problem is listed.
        $this->assertSame('fiscal_required', $preview['problems'][0]['code']);
        $this->assertStringContainsString('KRA eTIMS', $preview['html']);
        $this->assertStringContainsString('Receipt / Reçu', $preview['html']);
        $this->assertStringContainsString('Sample Traders Ltd', $preview['html']);

        $path = parse_url($preview['pdf_url'], PHP_URL_PATH);
        $pdf = $this->get($path, $this->headersFor())->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Another user of the tenant (an admin) does not get someone else's preview.
        $admin = $this->userWith('admin', Scope::tenant());
        $this->getJson($path, $this->headersFor($admin))->assertNotFound()->assertJsonPath('code', 'preview_expired');

        // An A4 sample type renders to a PDF too.
        $invoice = $this->postJson('/api/v1/templates/preview', ['type' => 'payroll.payslip', 'payload' => DefaultTemplates::for('payroll.payslip')], $this->headersFor())->assertOk()->json('data');
        $this->assertSame([], $invoice['problems']);
        $this->assertStringStartsWith('%PDF', $this->get(parse_url($invoice['pdf_url'], PHP_URL_PATH), $this->headersFor())->assertOk()->getContent());
    }

    public function test_a_malformed_draft_previews_with_problems_instead_of_failing(): void
    {
        $payload = $this->receipt();
        $payload['blocks'][] = ['id' => 'qr', 'type' => 'qr', 'content' => str_repeat('A', 5000)];
        $payload['blocks'][] = ['id' => 'bad-text', 'type' => 'text', 'text' => ['not', 'text']];
        $payload['blocks'][] = ['id' => 'bad-lines', 'type' => 'lines', 'columns' => 'item_name'];
        $payload['blocks'][] = 'not a block';

        $preview = $this->postJson('/api/v1/templates/preview', ['type' => 'pos.receipt', 'payload' => $payload], $this->headersFor())->assertOk()->json('data');

        $codes = array_map(fn ($p) => $p['path'].':'.$p['code'], $preview['problems']);
        $this->assertContains('blocks.15.content:max_length', $codes);
        $this->assertContains('blocks.16.text:type', $codes);
        $this->assertContains('blocks.17.columns:type', $codes);
        $this->assertContains('blocks.18.type:unknown_block', $codes);
        // The rest of the receipt still renders.
        $this->assertStringContainsString('KRA eTIMS', $preview['html']);
    }

    public function test_permissions_are_checked(): void
    {
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        // No template permission: refused everywhere.
        $this->getJson('/api/v1/templates/types', $this->headersFor($cashier))->assertForbidden();
        $this->postJson('/api/v1/templates/preview', ['type' => 'pos.receipt', 'payload' => $this->receipt()], $this->headersFor($cashier))->assertForbidden();
        $this->postJson(self::URL, ['key' => 'pos.receipt', 'scope_type' => 'branch', 'scope_id' => $this->branchA->id, 'payload' => $this->receipt()], $this->headersFor($cashier))->assertForbidden();

        // The branch manager sees templates (core.template.view) but may not edit them.
        $this->getJson('/api/v1/templates/types?scope_type=branch&scope_id='.$this->branchA->id, $this->headersFor($manager))->assertOk();
        $this->postJson(self::URL, ['key' => 'pos.receipt', 'scope_type' => 'branch', 'scope_id' => $this->branchA->id, 'payload' => $this->receipt()], $this->headersFor($manager))->assertForbidden();
        // A branch outside their scope is not found.
        $this->getJson('/api/v1/templates/types?scope_type=branch&scope_id='.$this->branchB->id, $this->headersFor($manager))->assertNotFound();
    }

    public function test_another_tenant_cannot_reach_templates_or_previews(): void
    {
        $other = $this->otherTenant();
        $document = $this->save('pos.receipt', $this->receipt());
        $preview = $this->postJson('/api/v1/templates/preview', ['type' => 'pos.receipt', 'payload' => $this->receipt()], $this->headersFor())->json('data.pdf_url');

        $this->getJson(self::URL.'/'.$document['data']['id'], $this->headersFor($other['user']))->assertNotFound();
        $this->getJson(parse_url($preview, PHP_URL_PATH), $this->headersFor($other['user']))->assertNotFound();
        // A's company as a scope: not found for B (row-level security).
        $this->getJson('/api/v1/templates/types?scope_type=company&scope_id='.$this->acme->id, $this->headersFor($other['user']))->assertNotFound();
        $this->postJson(self::URL, ['key' => 'pos.receipt', 'scope_type' => 'company', 'scope_id' => $this->acme->id, 'payload' => $this->receipt()], $this->headersFor($other['user']))
            ->assertUnprocessable()->assertJsonValidationErrors('scope_id');
    }
}
