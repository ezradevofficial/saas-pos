<?php

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\CRM\Http\Requests\Setup\AssignmentRuleRequest;
use Modules\CRM\Http\Requests\Setup\SetupListRequest;
use Modules\CRM\Http\Resources\SetupResource;
use Modules\CRM\Models\AssignmentRule;
use Modules\CRM\Setup\Configuration;

/** CRM-11: lead assignment rules, in order. */
class AssignmentRuleController
{
    private const RELATIONS = ['company', 'branch', 'user', 'team'];

    public function __construct(private readonly Configuration $configuration) {}

    public function rules(SetupListRequest $request): JsonResponse
    {
        $rules = $request->applyFilters(AssignmentRule::query())->with(self::RELATIONS)->orderBy('position')->orderBy('created_at')->get();

        return response()->json(['data' => $rules->map(fn (AssignmentRule $rule) => SetupResource::rule($rule))->values()]);
    }

    public function store(AssignmentRuleRequest $request): JsonResponse
    {
        return $this->present($this->configuration->saveRule(null, $request->company(), $request->validated()), 201);
    }

    public function update(AssignmentRuleRequest $request, AssignmentRule $crmAssignmentRule): JsonResponse
    {
        return $this->present($this->configuration->saveRule($crmAssignmentRule, $request->company(), $request->validated()));
    }

    public function archive(AssignmentRuleRequest $request, AssignmentRule $crmAssignmentRule): JsonResponse
    {
        $crmAssignmentRule->archive();

        return $this->present($crmAssignmentRule);
    }

    private function present(AssignmentRule $rule, int $status = 200): JsonResponse
    {
        return response()->json(['data' => SetupResource::rule($rule->refresh()->load(self::RELATIONS))], $status);
    }
}
