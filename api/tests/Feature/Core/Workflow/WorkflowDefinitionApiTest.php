<?php

namespace Tests\Feature\Core\Workflow;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Models\WorkflowDefinition;
use App\Core\Workflow\Models\WorkflowVersion;
use Illuminate\Database\QueryException;
use Tests\Concerns\BuildsWorkflows;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Workflow\ExtOrderType;
use Tests\Support\Workflow\Graphs;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * WF-01, WF-02, WF-03, APR-09, spec 6.4: document types, flows and their
 * versions through the API: create from the default, edit the draft,
 * validate, publish, roll back, copy between companies, restore the
 * default, test with a sample; permissions (RBAC-04) and other tenants.
 */
class WorkflowDefinitionApiTest extends TestCase
{
    use BuildsWorkflows, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
    }

    private function create(?string $companyId = null, ?array $headers = null): array
    {
        return $this->postJson('/api/v1/workflows', ['document_type' => TestRequestType::KEY, 'company_id' => $companyId], $headers ?? $this->headersFor())
            ->assertCreated()->json('data');
    }

    /** @return list<string> */
    private function auditActions(string $definitionId): array
    {
        return $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $definitionId)->orderBy('seq')->pluck('action')->all());
    }

    public function test_document_types_list_their_fields_operators_and_next_documents(): void
    {
        $response = $this->getJson('/api/v1/workflow/document-types', $this->headersFor())->assertOk();

        $type = collect($response->json('data'))->firstWhere('key', TestRequestType::KEY);
        $this->assertSame('Workflows', $type['label']);
        $total = collect($type['fields'])->firstWhere('name', 'total');
        $this->assertSame(['money', 'Document type'], [$total['type'], $total['label']]);
        $this->assertContains('gt', $total['operators']);
        $this->assertNotContains('contains', $total['operators']);
        $this->assertSame(['goods', 'services', 'travel'], collect($type['fields'])->firstWhere('name', 'category')['values']);
        $this->assertSame(['order', 'ext_order'], array_column($type['next_documents'], 'key'));
        $this->assertSame(['create_document', 'notify'], $response->json('meta.action_handlers'));

        // Without any workflow permission.
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->getJson('/api/v1/workflow/document-types', $this->headersFor($cashier))->assertForbidden();
    }

    public function test_a_flow_starts_as_a_draft_of_the_types_default_for_the_companys_country(): void
    {
        $flow = $this->create($this->acme->id);

        $this->assertSame([TestRequestType::KEY, $this->acme->id, 'Acme'], [$flow['document_type'], $flow['company_id'], $flow['company_name']]);
        $this->assertNull($flow['published']);
        $this->assertSame([1, 'draft', 'default'], [$flow['draft']['version'], $flow['draft']['status'], $flow['draft']['source']]);
        $this->assertSame(['start', 'review', 'end'], array_column($flow['draft']['graph']['nodes'], 'id'));
        $this->assertSame(['core.workflow.create'], $this->auditActions($flow['id']));

        // One flow per type and company.
        $this->postJson('/api/v1/workflows', ['document_type' => TestRequestType::KEY, 'company_id' => $this->acme->id], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'workflow_exists');
        // The flow for every company is another one.
        $this->assertNull($this->create()['company_id']);

        // CD companies get the CD default.
        $kinshasa = $this->inTenant(fn () => Company::create([
            'name' => 'Kin', 'legal_name' => 'Kin', 'country' => 'CD', 'base_currency' => 'CDF', 'fiscal_year_start_month' => 1, 'timezone' => 'Africa/Kinshasa',
        ]));
        $this->assertSame(['start', 'review', 'finance', 'end'], array_column($this->create($kinshasa->id)['draft']['graph']['nodes'], 'id'));

        $this->postJson('/api/v1/workflows', ['document_type' => 'core.unknown'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('document_type');
    }

    public function test_drafts_are_saved_validated_published_and_immutable(): void
    {
        $flow = $this->create($this->acme->id);
        $url = "/api/v1/workflows/{$flow['id']}";

        // Unreadable: refused, nothing saved.
        $this->putJson("{$url}/draft", ['graph' => ['nodes' => [['type' => 'start']], 'edges' => []]], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'workflow_invalid')->assertJsonPath('problems.0.code', 'invalid_node');

        // Readable but not publishable: saved, problems listed.
        $broken = Graphs::linear(['review']);
        $broken['nodes'][1]['name'] = '';
        $this->putJson("{$url}/draft", ['graph' => $broken], $this->headersFor())->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('meta.problems.0.code', 'missing_name');
        $this->postJson("{$url}/validate", [], $this->headersFor())->assertOk()->assertJsonPath('data.valid', false);
        $this->postJson("{$url}/publish", [], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'workflow_invalid')->assertJsonPath('problems.0.node', 'review');

        // Validate an unsaved graph.
        $this->postJson("{$url}/validate", ['graph' => Graphs::requisition()], $this->headersFor())->assertOk()
            ->assertJsonPath('data.valid', true)->assertJsonPath('data.problems', []);

        $this->putJson("{$url}/draft", ['graph' => Graphs::requisition()], $this->headersFor())->assertOk()->assertJsonPath('meta.problems', []);
        $published = $this->postJson("{$url}/publish", [], $this->headersFor())->assertOk();
        $published->assertJsonPath('data.published.version', 1)->assertJsonPath('data.draft', null);
        $this->assertSame('Check budget', $published->json('data.published.graph.nodes.1.name'));

        // APR-09: the database refuses changes to a published graph.
        $this->inTenant(function () use ($flow) {
            $version = WorkflowVersion::query()->where('definition_id', $flow['id'])->sole();

            try {
                $version->forceFill(['graph' => Graphs::linear(['x'])])->save();
                $this->fail('a published version changed');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        });
    }

    public function test_a_new_draft_becomes_the_next_version_and_roll_back_publishes_a_copy(): void
    {
        $flow = $this->create($this->acme->id);
        $url = "/api/v1/workflows/{$flow['id']}";
        $this->postJson("{$url}/publish", [], $this->headersFor())->assertOk();

        // Draft v2 while v1 is live (the builder's "Draft v4 · v3 is live").
        $this->putJson("{$url}/draft", ['graph' => Graphs::linear(['one', 'two'])], $this->headersFor())->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson($url, $this->headersFor())->assertOk()
            ->assertJsonPath('data.published.version', 1)->assertJsonPath('data.draft.version', 2);

        // A document still running on v1.
        $this->start($this->document());

        $this->postJson("{$url}/publish", [], $this->headersFor())->assertOk()->assertJsonPath('data.published.version', 2);

        $versions = $this->getJson("{$url}/versions", $this->headersFor())->assertOk()->json('data');
        $this->assertSame([[2, 'published', 0], [1, 'archived', 1]], array_map(fn ($v) => [$v['version'], $v['status'], $v['in_progress']], $versions));

        // Roll back to v1: published as v3, a copy.
        $this->postJson("{$url}/rollback", ['version' => 2], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'version_is_live');
        $this->postJson("{$url}/rollback", ['version' => 9], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'version_not_found');
        $rolled = $this->postJson("{$url}/rollback", ['version' => 1], $this->headersFor())->assertOk();
        $rolled->assertJsonPath('data.published.version', 3)->assertJsonPath('data.published.source', 'rollback')
            ->assertJsonPath('data.published.graph.nodes.1.id', 'review');

        $versionId = $rolled->json('data.published.id');
        $this->getJson("/api/v1/workflow-versions/{$versionId}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.version', 3)->assertJsonPath('data.in_progress', 0)->assertJsonPath('data.graph.nodes.1.id', 'review');

        // A draft opened before a rollback is numbered above it when published.
        $this->putJson("{$url}/draft", ['graph' => Graphs::linear(['later'])], $this->headersFor())->assertOk()->assertJsonPath('data.version', 4);

        $this->assertSame([
            'core.workflow.create', 'core.workflow.publish', 'core.workflow.draft_create', 'core.workflow.publish',
            'core.workflow.rollback', 'core.workflow.draft_create',
        ], $this->auditActions($flow['id']));
    }

    public function test_copy_to_another_company_and_restore_the_default(): void
    {
        $flow = $this->create($this->acme->id);
        $url = "/api/v1/workflows/{$flow['id']}";
        $this->putJson("{$url}/draft", ['graph' => Graphs::requisition()], $this->headersFor())->assertOk();

        // Nothing live yet: copy the draft instead.
        $other = $this->inTenant(fn () => $this->company('Beta'));
        $this->postJson("{$url}/copy", ['company_id' => $other->id], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'nothing_published');
        $copy = $this->postJson("{$url}/copy", ['company_id' => $other->id, 'from' => 'draft'], $this->headersFor())->assertCreated();
        $copy->assertJsonPath('data.company_id', $other->id)->assertJsonPath('data.draft.source', 'copy')
            ->assertJsonPath('data.draft.graph.nodes.1.id', 'check_budget')->assertJsonPath('data.published', null);

        // Copying again replaces that company's draft; same company is refused; every company is a target.
        $this->postJson("{$url}/publish", [], $this->headersFor())->assertOk();
        $again = $this->postJson("{$url}/copy", ['company_id' => $other->id], $this->headersFor())->assertCreated();
        $this->assertSame($copy->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, $again->json('data.draft.version'));
        $this->postJson("{$url}/copy", ['company_id' => $this->acme->id], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'same_company');
        $this->postJson("{$url}/copy", ['company_id' => null], $this->headersFor())->assertCreated()->assertJsonPath('data.company_id', null);
        $this->assertSame(['core.workflow.copy', 'core.workflow.copy'], $this->auditActions($copy->json('data.id')));

        // WF-02: the original can always be restored (as the draft).
        $restored = $this->postJson("{$url}/restore-default", [], $this->headersFor())->assertOk();
        $restored->assertJsonPath('data.draft.source', 'default')->assertJsonPath('data.draft.version', 2)
            ->assertJsonPath('data.published.version', 1);
        $this->assertSame(['start', 'review', 'end'], array_column($restored->json('data.draft.graph.nodes'), 'id'));
    }

    public function test_test_with_a_sample_shows_the_path_and_the_reasons_without_writing(): void
    {
        $flow = $this->create($this->acme->id);
        $url = "/api/v1/workflows/{$flow['id']}";
        $this->putJson("{$url}/draft", ['graph' => Graphs::requisition()], $this->headersFor())->assertOk();
        $before = $this->inTenant(fn () => [DocumentWorkflow::query()->count(), AuditEntry::query()->count()]);

        $big = $this->postJson("{$url}/test", ['values' => ['total' => Graphs::kes(30000000), 'budget' => Graphs::kes(50000000)]], $this->headersFor())->assertOk();
        $this->assertSame(['start', 'check_budget', 'branch_manager', 'over_limit', 'cfo', 'create_order', 'notify', 'approved'], array_column($big->json('data.path'), 'node_id'));
        $this->assertSame('yes', $big->json('data.path.3.result'));
        $this->assertSame(['Rule met: Document type must be more than KES 250,000.00; it is KES 300,000.00.'], $big->json('data.path.3.reasons'));
        $this->assertSame('Would create a draft Draft.', $big->json('data.path.5.reasons.0'));
        $this->assertSame('approved', $big->json('data.outcome'));

        $small = $this->postJson("{$url}/test", ['values' => ['total' => Graphs::kes(10000000), 'budget' => Graphs::kes(50000000)]], $this->headersFor())->assertOk();
        $this->assertNotContains('cfo', array_column($small->json('data.path'), 'node_id'));
        $this->assertSame('no', $small->json('data.path.3.result'));

        $rejected = $this->postJson("{$url}/test", ['values' => ['total' => Graphs::kes(30000000), 'budget' => Graphs::kes(50000000)], 'outcomes' => ['cfo' => 'rejected']], $this->headersFor())->assertOk();
        $this->assertSame('rejected', $rejected->json('data.outcome'));

        $blocked = $this->postJson("{$url}/test", ['values' => ['total' => Graphs::kes(30000000), 'budget' => Graphs::kes(100)]], $this->headersFor())->assertOk();
        $blocked->assertJsonPath('data.blocked.node_id', 'check_budget')->assertJsonPath('data.blocked.rule', 'entry')->assertJsonPath('data.outcome', null);
        $this->assertSame(['Document type must be at most Company; it is KES 300,000.00.'], $blocked->json('data.blocked.reasons'));

        // An invalid graph answers its problems instead.
        $invalid = Graphs::linear(['x']);
        $invalid['nodes'][1]['name'] = '';
        $this->postJson("{$url}/test", ['values' => [], 'graph' => $invalid], $this->headersFor())->assertOk()
            ->assertJsonPath('data.valid', false)->assertJsonPath('data.problems.0.code', 'missing_name');

        $this->assertSame($before, $this->inTenant(fn () => [DocumentWorkflow::query()->count(), AuditEntry::query()->count()]));
    }

    public function test_parallel_any_in_a_dry_run_closes_the_other_branch(): void
    {
        $flow = $this->create($this->acme->id);
        $result = $this->postJson("/api/v1/workflows/{$flow['id']}/test", ['values' => [], 'graph' => Graphs::parallel('any')], $this->headersFor())->assertOk();

        $this->assertSame(['start', 'prepare', 'split', 'it', 'payroll', 'join', 'close', 'end'], array_column($result->json('data.path'), 'node_id'));
        $this->assertSame('completed', $result->json('data.outcome'));
    }

    public function test_the_list_shows_flows_the_user_reaches_with_filters_sort_and_export(): void
    {
        $acmeFlow = $this->create($this->acme->id);
        $everyFlow = $this->create();
        $beta = $this->inTenant(fn () => $this->company('Beta'));
        $betaFlow = $this->create($beta->id);

        $all = $this->getJson('/api/v1/workflows?sort=company', $this->headersFor())->assertOk();
        $this->assertSame([$everyFlow['id'], $acmeFlow['id'], $betaFlow['id']], array_column($all->json('data'), 'id'));

        $this->assertSame([$everyFlow['id'], $acmeFlow['id']], array_column($this->getJson("/api/v1/workflows?company={$this->acme->id}&sort=company", $this->headersFor())->assertOk()->json('data'), 'id'));
        $this->assertCount(3, $this->getJson('/api/v1/workflows?type='.TestRequestType::KEY, $this->headersFor())->assertOk()->json('data'));
        $this->getJson('/api/v1/workflows?type=core.nothing', $this->headersFor())->assertUnprocessable();

        // RBAC-04: a viewer at Acme sees Acme's flow and the one for every company, not Beta's.
        $viewer = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Flow viewer', ['core.workflow.view']), Scope::company($this->acme->id));

            return $user;
        });
        $this->assertEqualsCanonicalizing([$everyFlow['id'], $acmeFlow['id']], array_column($this->getJson('/api/v1/workflows', $this->headersFor($viewer))->assertOk()->json('data'), 'id'));
        $this->getJson("/api/v1/workflows/{$betaFlow['id']}", $this->headersFor($viewer))->assertNotFound();

        $csv = $this->get('/api/v1/workflows?format=csv&sort=company', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('All companies', $csv);
        $this->assertStringContainsString('Beta', $csv);
    }

    public function test_editing_and_publishing_need_their_permissions_at_the_flows_company(): void
    {
        $acmeFlow = $this->create($this->acme->id);
        $everyFlow = $this->create();
        $beta = $this->inTenant(fn () => $this->company('Beta'));
        $betaFlow = $this->create($beta->id);

        [$viewer, $editor, $publisher] = $this->inTenant(function () {
            $make = function (string $name, array $permissions) {
                $user = $this->colleague($this->owner);
                $this->assign($user, $this->role($name, $permissions), Scope::company($this->acme->id));

                return $user;
            };

            return [
                $make('Viewer', ['core.workflow.view']),
                $make('Editor', ['core.workflow.view', 'core.workflow.edit']),
                $make('Publisher', ['core.workflow.view', 'core.workflow.publish']),
            ];
        });
        $graph = ['graph' => Graphs::linear(['a'])];

        // Viewer: reads and tests, never changes.
        $this->getJson("/api/v1/workflows/{$acmeFlow['id']}", $this->headersFor($viewer))->assertOk();
        $this->postJson("/api/v1/workflows/{$acmeFlow['id']}/test", ['values' => []], $this->headersFor($viewer))->assertOk();
        $this->putJson("/api/v1/workflows/{$acmeFlow['id']}/draft", $graph, $this->headersFor($viewer))->assertForbidden();
        $this->postJson("/api/v1/workflows/{$acmeFlow['id']}/publish", [], $this->headersFor($viewer))->assertForbidden();
        $this->postJson('/api/v1/workflows', ['document_type' => TestRequestType::KEY, 'company_id' => $this->acme->id], $this->headersFor($viewer))->assertForbidden();

        // Editor at Acme: edits Acme's draft, not the flow for every company (tenant scope), cannot publish.
        $this->putJson("/api/v1/workflows/{$acmeFlow['id']}/draft", $graph, $this->headersFor($editor))->assertOk();
        $this->postJson("/api/v1/workflows/{$acmeFlow['id']}/restore-default", [], $this->headersFor($editor))->assertOk();
        $this->putJson("/api/v1/workflows/{$everyFlow['id']}/draft", $graph, $this->headersFor($editor))->assertForbidden();
        $this->postJson("/api/v1/workflows/{$acmeFlow['id']}/publish", [], $this->headersFor($editor))->assertForbidden();
        // Beta's flow is not even found; copying there is refused as an unknown company.
        $this->putJson("/api/v1/workflows/{$betaFlow['id']}/draft", $graph, $this->headersFor($editor))->assertNotFound();
        $this->postJson("/api/v1/workflows/{$acmeFlow['id']}/copy", ['company_id' => $beta->id, 'from' => 'draft'], $this->headersFor($editor))
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->postJson("/api/v1/workflows/{$acmeFlow['id']}/copy", ['company_id' => null, 'from' => 'draft'], $this->headersFor($editor))->assertForbidden();

        // Publisher at Acme: publishes and rolls back Acme's flow only.
        $this->postJson("/api/v1/workflows/{$acmeFlow['id']}/publish", [], $this->headersFor($publisher))->assertOk();
        $this->postJson("/api/v1/workflows/{$everyFlow['id']}/publish", [], $this->headersFor($publisher))->assertForbidden();

        // No workflow permission at all.
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->getJson('/api/v1/workflows', $this->headersFor($cashier))->assertForbidden();
        $this->getJson("/api/v1/workflows/{$acmeFlow['id']}", $this->headersFor($cashier))->assertNotFound();
    }

    public function test_flows_of_a_type_whose_module_is_switched_off_are_not_found(): void
    {
        // M3 regression: such flows used to answer 500.
        app(ModuleRegistry::class)->register(ExtOrderType::MODULE);
        app(DocumentTypeRegistry::class)->register(ExtOrderType::class);
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate(ExtOrderType::MODULE));

        $flow = $this->postJson('/api/v1/workflows', ['document_type' => ExtOrderType::KEY], $this->headersFor())->assertCreated()->json('data');
        $this->getJson("/api/v1/workflows/{$flow['id']}", $this->headersFor())->assertOk();

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate(ExtOrderType::MODULE));
        $this->getJson("/api/v1/workflows/{$flow['id']}", $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/workflows/{$flow['id']}/restore-default", [], $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/workflows/{$flow['id']}/validate", [], $this->headersFor())->assertNotFound();
        $this->assertNotContains($flow['id'], array_column($this->getJson('/api/v1/workflows', $this->headersFor())->json('data'), 'id'));
    }

    public function test_another_tenants_flows_are_not_found(): void
    {
        $flow = $this->create($this->acme->id);
        $version = $this->inTenant(fn () => WorkflowDefinition::query()->findOrFail($flow['id'])->draft()->value('id'));
        $other = $this->otherTenant();
        $theirs = $this->headersFor($other['user']);

        $this->getJson("/api/v1/workflows/{$flow['id']}", $theirs)->assertNotFound();
        $this->getJson("/api/v1/workflows/{$flow['id']}/versions", $theirs)->assertNotFound();
        $this->getJson("/api/v1/workflow-versions/{$version}", $theirs)->assertNotFound();
        $this->putJson("/api/v1/workflows/{$flow['id']}/draft", ['graph' => Graphs::linear(['a'])], $theirs)->assertNotFound();
        $this->postJson("/api/v1/workflows/{$flow['id']}/publish", [], $theirs)->assertNotFound();
        $this->postJson("/api/v1/workflows/{$flow['id']}/test", ['values' => []], $theirs)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/workflows', $theirs)->assertOk()->json('data'));

        // Their company id in a body is unknown here.
        $this->postJson('/api/v1/workflows', ['document_type' => TestRequestType::KEY, 'company_id' => $other['company']->id], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->postJson("/api/v1/workflows/{$flow['id']}/copy", ['company_id' => $other['company']->id, 'from' => 'draft'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
    }
}
