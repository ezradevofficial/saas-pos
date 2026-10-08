<?php

namespace App\Core\Automation\Http\Controllers;

use App\Core\Automation\AutomationAccess;
use App\Core\Automation\Http\Requests\ListRunsRequest;
use App\Core\Automation\Http\Requests\RunRequest;
use App\Core\Automation\Http\Resources\AutomationRunResource;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Exports\ListExport;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** AUTO-05: the run log, of the rules the user may see, for documents in their companies (RBAC-04). */
class AutomationRunController
{
    public function index(ListRunsRequest $request, AutomationAccess $access, DocumentTypeRegistry $types, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $rules = AutomationRule::query()->whereIn('document_type', $types->keys());
        $companies = $access->companyIds($request->user());

        if ($companies !== null) {
            $rules->where(fn ($q) => $q->whereNull('company_id')->orWhereIn('company_id', $companies));
        }

        $query = AutomationRun::query()->with(['rule', 'deliveries'])->whereIn('rule_id', $rules->select('id'));

        // RBAC-04: runs of a rule for every company only for documents in the reader's companies.
        if ($companies !== null) {
            $query->where(fn ($q) => $q->whereNull('company_id')->orWhereIn('company_id', $companies));
        }

        if (($rule = $request->validated('rule')) !== null) {
            $query->where('rule_id', $rule);
        }

        if (($outcome = $request->validated('outcome')) !== null) {
            $query->where('outcome', $outcome);
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return AutomationRunResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(RunRequest $request, AutomationRun $automationRun): AutomationRunResource
    {
        return AutomationRunResource::make($automationRun->load(['rule', 'deliveries']));
    }
}
