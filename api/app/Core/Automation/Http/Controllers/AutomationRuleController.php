<?php

namespace App\Core\Automation\Http\Controllers;

use App\Core\Automation\Actions\RuleContext;
use App\Core\Automation\AutomationAccess;
use App\Core\Automation\Http\Requests\ChangeRuleRequest;
use App\Core\Automation\Http\Requests\ListRulesRequest;
use App\Core\Automation\Http\Requests\RuleRequest;
use App\Core\Automation\Http\Requests\StoreRuleRequest;
use App\Core\Automation\Http\Requests\TestRuleRequest;
use App\Core\Automation\Http\Requests\TestUnsavedRuleRequest;
use App\Core\Automation\Http\Requests\UpdateRuleRequest;
use App\Core\Automation\Http\Resources\AutomationRuleResource;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Runtime\Rules;
use App\Core\Automation\Runtime\RuleTester;
use App\Core\Automation\Runtime\RuleValidator;
use App\Core\Automation\Triggers\Triggers;
use App\Core\Exports\ListExport;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AUTO-01..AUTO-04: automation rules: list, create, read, edit, enable,
 * disable, archive (never delete) and test (a saved rule, or the editor's
 * unsaved one). Changes are audited by Rules (`core.automation.*`); test
 * runs are neither logged nor audited.
 */
class AutomationRuleController
{
    public function __construct(
        private readonly Rules $rules,
        private readonly DocumentTypeRegistry $types,
    ) {}

    public function index(ListRulesRequest $request, AutomationAccess $access, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = AutomationRule::query()->with('company')->whereIn('document_type', $this->types->keys());
        $companies = $access->companyIds($request->user());

        // RBAC-04: rules of companies the user reaches, and those of every company.
        if ($companies !== null) {
            $query->where(fn ($q) => $q->whereNull('company_id')->orWhereIn('company_id', $companies));
        }

        if (($type = $request->validated('type')) !== null) {
            $query->where('document_type', $type);
        }

        if (($company = $request->validated('company')) !== null) {
            $query->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company));
        }

        $request->applyStatus($query);
        $request->applySort($request->applySearch($query, ['name' => 'name']));

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return AutomationRuleResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreRuleRequest $request): JsonResponse
    {
        $rule = $this->rules->create($request->validated(), $request->user());

        return AutomationRuleResource::make($rule->load('company'))->response()->setStatusCode(201);
    }

    public function show(RuleRequest $request, AutomationRule $automationRule): AutomationRuleResource
    {
        return AutomationRuleResource::make($automationRule->load('company'));
    }

    public function update(UpdateRuleRequest $request, AutomationRule $automationRule): AutomationRuleResource
    {
        $rule = $this->rules->update($automationRule, $request->validated(), $request->user());

        return AutomationRuleResource::make($rule->load('company'));
    }

    /** AUTO-03: a new webhook signing secret, returned in this response only. */
    public function rotateSecret(ChangeRuleRequest $request, AutomationRule $automationRule): AutomationRuleResource
    {
        return AutomationRuleResource::make($this->rules->rotateSecret($automationRule, $request->user())->load('company'));
    }

    public function enable(ChangeRuleRequest $request, AutomationRule $automationRule, RuleValidator $validator): AutomationRuleResource
    {
        // The rule is checked again: its type's module, roles or users it names may have gone.
        $this->assertValid($automationRule, $validator, $request->user());

        return AutomationRuleResource::make($this->rules->setEnabled($automationRule, true, $request->user())->fresh('company'));
    }

    public function disable(ChangeRuleRequest $request, AutomationRule $automationRule): AutomationRuleResource
    {
        return AutomationRuleResource::make($this->rules->setEnabled($automationRule, false, $request->user())->fresh('company'));
    }

    public function archive(ChangeRuleRequest $request, AutomationRule $automationRule): AutomationRuleResource
    {
        return AutomationRuleResource::make($this->rules->archive($automationRule, $request->user())->fresh('company'));
    }

    public function test(TestRuleRequest $request, AutomationRule $automationRule, RuleTester $tester): JsonResponse
    {
        $type = $this->types->find($automationRule->document_type)
            ?? throw new ApiException(422, 'type_unavailable', __('automation.errors.type_unavailable'));

        return new JsonResponse(['data' => $tester->test(
            $automationRule, $type, $request->documentId(), $request->documentScope(),
            $request->testValues(), $request->oldValues(), $request->user(),
        )]);
    }

    public function testUnsaved(TestUnsavedRuleRequest $request, RuleTester $tester): JsonResponse
    {
        $data = $request->validated();
        $type = $this->types->get($data['document_type']);
        $rule = new AutomationRule([
            'name' => $data['name'] ?? '',
            'document_type' => $type->key(),
            'company_id' => $data['company_id'] ?? null,
            'trigger_type' => $data['trigger']['type'],
            'trigger' => $data['trigger'],
            'conditions' => $data['conditions'] ?? null,
            'actions' => $data['actions'],
        ]);

        return new JsonResponse(['data' => $tester->test(
            $rule, $type, $request->documentId(), $request->documentScope(),
            $request->testValues(), $request->oldValues(), $request->user(),
        )]);
    }

    private function assertValid(AutomationRule $rule, RuleValidator $validator, User $user): void
    {
        $type = $this->types->find($rule->document_type)
            ?? throw new ApiException(422, 'type_unavailable', __('automation.errors.type_unavailable'));
        $context = new RuleContext($type, Triggers::hasDocument($rule->trigger), $rule->company_id, $user);
        $problems = $validator->validate($rule->definition(), $context);

        if ($problems !== []) {
            $errors = [];

            foreach ($problems as $problem) {
                $errors[$problem['path']][] = $problem['message'];
            }

            throw new ApiException(422, 'rule_invalid', __('automation.errors.rule_invalid'), $errors);
        }
    }
}
