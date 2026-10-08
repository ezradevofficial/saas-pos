<?php

namespace Tests\Feature\Core\Approvals;

use App\Core\Approvals\ApprovalConfig;
use App\Core\Approvals\Resolvers\ApprovalSubject;
use App\Core\Approvals\Resolvers\ApproverResolvers;
use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsApprovals;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Workflow\TestDocuments;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * APR-02: each approver type resolves to the right people for a document
 * at a place, and node settings are validated (and listed for the builder).
 */
class ApproverResolutionTest extends TestCase
{
    use BuildsApprovals, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpApprovals();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return list<string> */
    private function resolve(array $approver, DocumentScope $scope, array $values = [], ?DocumentType $type = null): array
    {
        return $this->inTenant(function () use ($approver, $scope, $values, $type) {
            $type ??= app(DocumentTypeRegistry::class)->get(TestRequestType::KEY);
            $id = TestDocuments::create($type->key(), $values, $scope);
            $subject = new ApprovalSubject($type, $id, $scope, app(ScopeResolver::class)->chainOf($scope->scope()), []);
            $found = app(ApproverResolvers::class)->find($approver['type'])->resolve($approver, $subject);
            sort($found);

            return $found;
        });
    }

    private function sorted(string ...$ids): array
    {
        sort($ids);

        return $ids;
    }

    public function test_branch_manager_is_the_branchs_holder_else_the_companys(): void
    {
        $atLocation = new DocumentScope(null, null, $this->locationA->id);
        $this->assertSame([$this->managerA->id], $this->resolve(['type' => 'branch_manager'], $atLocation));
        $this->assertSame([$this->managerB->id], $this->resolve(['type' => 'branch_manager'], new DocumentScope($this->acme->id, $this->branchB->id)));

        $companyLevel = $this->person('branch_manager', Scope::company($this->acme->id), 'Cora Company BM');
        $this->assertSame([$companyLevel->id], $this->resolve(['type' => 'branch_manager'], new DocumentScope($this->acme->id)));
        $this->inTenant(fn () => $this->managerB->forceFill(['status' => 'deactivated'])->save());
        $this->assertSame([$companyLevel->id], $this->resolve(['type' => 'branch_manager'], new DocumentScope($this->acme->id, $this->branchB->id)));
    }

    public function test_role_covers_the_documents_place_only(): void
    {
        $atA = $this->person('accountant', Scope::branch($this->branchA->id), 'Abe Accountant A');
        $this->person('accountant', Scope::branch($this->branchB->id), 'Bo Accountant B');

        $this->assertSame($this->sorted($this->accountant->id, $atA->id), $this->resolve(['type' => 'role', 'role' => 'template:accountant'], new DocumentScope($this->acme->id, $this->branchA->id)));
    }

    public function test_user_and_manager_levels_up(): void
    {
        $this->assertSame([$this->accountant->id], $this->resolve(['type' => 'user', 'user_id' => $this->accountant->id], new DocumentScope($this->acme->id)));

        $companyAdmin = $this->person('admin', Scope::company($this->acme->id), 'Carl Company Admin');
        $atLocation = new DocumentScope($this->acme->id, $this->branchA->id, $this->locationA->id);
        $this->assertSame([$this->managerA->id], $this->resolve(['type' => 'manager_levels_up', 'levels' => 1], $atLocation));
        $this->assertSame([$companyAdmin->id], $this->resolve(['type' => 'manager_levels_up', 'levels' => 2], $atLocation));
        $this->assertSame([$this->owner->id], $this->resolve(['type' => 'manager_levels_up', 'levels' => 3], $atLocation));
        $this->assertSame([], $this->resolve(['type' => 'manager_levels_up', 'levels' => 4], $atLocation));
    }

    public function test_department_head_and_cost_centre_owner_come_from_the_documents_dimensions(): void
    {
        $type = new class extends TestRequestType
        {
            public function key(): string
            {
                return 'core.test_expense';
            }

            public function fields(): array
            {
                return [
                    FieldDefinition::reference('department', 'workflow.attributes.node', 'core.department'),
                    FieldDefinition::reference('cost_centre', 'workflow.attributes.node', 'core.cost_centre'),
                ];
            }

            public function fieldValues(string $documentId): array
            {
                return TestDocuments::find($this->key(), $documentId)['values'] ?? [];
            }
        };
        app(DocumentTypeRegistry::class)->register($type);

        [$department, $costCentre] = $this->inTenant(fn () => [
            Department::create(['company_id' => $this->acme->id, 'code' => 'OPS', 'name' => 'Operations', 'owner_user_id' => $this->managerB->id]),
            CostCentre::create(['company_id' => $this->acme->id, 'code' => 'CC1', 'name' => 'Stores', 'owner_user_id' => $this->accountant->id]),
        ]);
        $scope = new DocumentScope($this->acme->id, $this->branchA->id);
        $values = ['department' => $department->id, 'cost_centre' => $costCentre->id];

        $this->assertSame([$this->managerB->id], $this->resolve(['type' => 'department_head'], $scope, $values, $type));
        $this->assertSame([$this->accountant->id], $this->resolve(['type' => 'cost_centre_owner'], $scope, $values, $type));
        $this->assertSame([], $this->resolve(['type' => 'department_head'], $scope, [], $type));

        // The test request type has no department: the builder is told.
        $problems = $this->inTenant(fn () => app(ApprovalConfig::class)->validate(['approval' => ['approver' => ['type' => 'department_head']]], app(DocumentTypeRegistry::class)->get(TestRequestType::KEY)));
        $this->assertNotEmpty($problems);
        $this->assertSame([], $this->inTenant(fn () => app(ApprovalConfig::class)->validate(['approval' => ['approver' => ['type' => 'department_head']]], $type)));
    }

    public function test_node_settings_are_validated_and_the_builder_lists_approver_types(): void
    {
        $type = app(DocumentTypeRegistry::class)->get(TestRequestType::KEY);
        $validate = fn (array $node) => $this->inTenant(fn () => app(ApprovalConfig::class)->validate($node, $type));

        $this->assertSame([], $validate([
            'approval' => ['approver' => ['type' => 'role', 'role' => 'template:accountant'], 'mode' => 'majority', 'allow_delegation' => false, 'allow_email' => true, 'require_reason' => true],
            'escalation' => ['after' => ['amount' => 8, 'unit' => 'business_hours'], 'to' => ['type' => 'user', 'user_id' => $this->accountant->id], 'final' => 'reject'],
            'reminders' => [['amount' => 4, 'unit' => 'business_hours']],
        ]));
        $this->assertSame([], $validate(['approval' => ['chain' => [['type' => 'branch_manager'], ['type' => 'manager_levels_up', 'levels' => 2]]]]));
        $this->assertSame([], $validate(['approval' => ['approver' => ['type' => 'branch_manager']], 'escalation' => ['after' => ['amount' => 1, 'unit' => 'days'], 'to' => 'next_level']]));

        foreach ([
            ['approval' => ['a', 'list']],
            ['approval' => ['approver' => ['type' => 'nope']]],
            ['approval' => ['approver' => ['type' => 'manager_levels_up', 'levels' => 9]]],
            ['approval' => ['approver' => ['type' => 'role', 'role' => 'template:nope']]],
            ['approval' => ['approver' => ['type' => 'user', 'user_id' => '00000000-0000-7000-8000-000000000000']]],
            ['approval' => ['chain' => []]],
            ['approval' => ['mode' => 'most']],
            ['approval' => ['allow_bulk' => 'yes']],
            ['approval' => [], 'escalation' => ['to' => ['type' => 'role', 'role' => 'template:accountant']]],
            ['approval' => [], 'escalation' => ['after' => ['amount' => 1, 'unit' => 'weeks']]],
            ['approval' => [], 'escalation' => ['final' => 'approve']],
            ['approval' => [], 'reminders' => [['amount' => 0, 'unit' => 'hours']]],
        ] as $node) {
            $this->assertNotEmpty($validate($node), json_encode($node));
        }

        $other = $this->otherTenant();
        $this->assertNotEmpty($validate(['approval' => ['approver' => ['type' => 'user', 'user_id' => $other['user']->id]]]));

        $meta = $this->getJson('/api/v1/workflow/document-types', $this->headersFor())->assertOk()->json('meta.approver_types');
        $this->assertSame(['branch_manager', 'department_head', 'cost_centre_owner', 'manager_levels_up', 'role', 'user'], array_column($meta, 'key'));
        $this->assertSame('levels', $meta[3]['params'][0]['name']);
    }

    public function test_an_invalid_approval_config_blocks_publishing(): void
    {
        $graph = $this->approvalGraph(['approver' => ['type' => 'nope']]);
        $definition = $this->postJson('/api/v1/workflows', ['document_type' => TestRequestType::KEY, 'company_id' => $this->acme->id], $this->headersFor())->assertCreated()->json('data.id');
        $problems = $this->putJson("/api/v1/workflows/{$definition}/draft", ['graph' => $graph], $this->headersFor())->assertOk()->json('meta.problems');
        $this->assertContains('approval', array_column($problems, 'code'));
    }
}
