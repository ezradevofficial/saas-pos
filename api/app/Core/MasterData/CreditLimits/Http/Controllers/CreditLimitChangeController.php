<?php

namespace App\Core\MasterData\CreditLimits\Http\Controllers;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Exports\ListExport;
use App\Core\Http\ApiException;
use App\Core\MasterData\CreditLimits\CreditLimitChange;
use App\Core\MasterData\CreditLimits\CreditLimitChangeAccess;
use App\Core\MasterData\CreditLimits\CreditLimitChanges;
use App\Core\MasterData\CreditLimits\CreditLimitChangeType;
use App\Core\MasterData\CreditLimits\Http\Requests\ApplyCreditLimitChangeRequest;
use App\Core\MasterData\CreditLimits\Http\Requests\CancelCreditLimitChangeRequest;
use App\Core\MasterData\CreditLimits\Http\Requests\CreditLimitChangeRequest;
use App\Core\MasterData\CreditLimits\Http\Requests\ListCreditLimitChangesRequest;
use App\Core\MasterData\CreditLimits\Http\Requests\StoreCreditLimitChangeRequest;
use App\Core\MasterData\CreditLimits\Http\Resources\CreditLimitChangeResource;
use App\Core\MasterData\Parties\Party;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MD-01, WF-01, WF-10, WF-11: credit limit change requests. Creating one
 * submits it to its flow; the detail carries the flow's status and history
 * and, while an approval waits, the approval's id (APR-04).
 */
class CreditLimitChangeController
{
    private const RELATIONS = ['party', 'company', 'requester', 'decider'];

    public function __construct(
        private readonly CreditLimitChanges $changes,
        private readonly CreditLimitChangeAccess $access,
        private readonly WorkflowEngine $engine,
    ) {}

    public function index(ListCreditLimitChangesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = CreditLimitChange::query()->with(self::RELATIONS);
        $companies = $this->access->listableCompanies($request->user());

        if ($companies !== null) {
            $query->whereIn('company_id', $companies);
        }

        foreach (['status' => 'status', 'party' => 'party_id', 'company' => 'company_id'] as $filter => $column) {
            if ($request->filled($filter)) {
                $query->where($column, $request->validated($filter));
            }
        }

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('number', 'ilike', $like)
                ->orWhereIn('party_id', Party::query()->where('name', 'ilike', $like)->select('id')));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return CreditLimitChangeResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreCreditLimitChangeRequest $request): JsonResponse
    {
        $change = $this->changes->request(
            $request->party(),
            $request->companyId(),
            $request->requestedLimit(),
            (string) $request->validated('reason'),
            $request->user(),
        );

        return $this->respond($request, $change, 201);
    }

    public function show(CreditLimitChangeRequest $request): JsonResponse
    {
        return $this->respond($request, $request->change());
    }

    public function cancel(CancelCreditLimitChangeRequest $request): JsonResponse
    {
        $this->changes->cancel($request->change(), $request->user(), (string) $request->validated('reason'));

        return $this->respond($request, $request->change());
    }

    /** Apply an approved request that was not applied (its job failed), for set_directly holders. */
    public function apply(ApplyCreditLimitChangeRequest $request): JsonResponse
    {
        $change = $request->change();

        if (! $this->changes->apply($change->id)) {
            throw new ApiException(422, 'credit_limit_change_not_approved', __('core.credit_limit_change.errors.not_approved'));
        }

        return $this->respond($request, $change);
    }

    /** The request with its flow (WF-10) and, while an approval waits on it, the approval's id. */
    private function respond(Request $request, CreditLimitChange $change, int $status = 200): JsonResponse
    {
        $change = $change->fresh(self::RELATIONS);
        $workflow = $this->engine->current(CreditLimitChangeType::KEY, $change->id);

        return CreditLimitChangeResource::make($change)->additional(['meta' => [
            'workflow' => $workflow === null ? null : $this->engine->status($workflow, $request->user()),
            'approval_id' => ApprovalRequest::query()
                ->where('document_type', CreditLimitChangeType::KEY)
                ->where('document_id', $change->id)
                ->where('status', ApprovalRequest::PENDING)
                ->value('id'),
        ]])->response()->setStatusCode($status);
    }
}
