<?php

namespace Modules\CRM\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use Illuminate\Http\JsonResponse;
use Modules\CRM\Http\Requests\ReadRequest;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\Stage;
use Modules\CRM\Overview\CustomerOverview;
use Modules\CRM\Support\CrmAccess;

/** CRM-10, CRM-16, CRM-25: the customer 360 view, the owners a record may be given to, and a pipeline's value by stage. */
class ReadController
{
    public function __construct(private readonly CrmAccess $access) {}

    public function overview(ReadRequest $request, Party $party, CustomerOverview $overview): JsonResponse
    {
        return response()->json(['data' => $overview->for($party, $request->user(), $request)]);
    }

    /**
     * CRM-10: active users who may view leads at the branch (or, without
     * one, at a branch of a company the reader reaches), by name.
     */
    public function assignees(ReadRequest $request, ScopeResolver $resolver): JsonResponse
    {
        // A branch the caller doesn't reach is not found (review): no list of who works there.
        if ($request->filled('branch')) {
            $branch = (string) $request->validated('branch');
            abort_unless(collect(['crm.lead.assign', CrmAccess::SCOPE_TEAM, CrmAccess::SCOPE_BRANCH, 'crm.target.manage'])
                ->contains(fn (string $permission) => $this->access->canAt($request->user(), $permission, $branch)), 404);
        }

        $branches = $request->filled('branch')
            ? [$request->validated('branch')]
            : Branch::query()->whereNull('archived_at')
                ->when(($companies = $this->access->companies($request->user(), 'crm.lead.view', 'crm.lead.assign', 'crm.target.manage', 'crm.settings.manage')) !== null, fn ($q) => $q->whereIn('company_id', $companies))
                ->limit(200)->pluck('id')->all();
        $ids = [];

        foreach ($branches as $branchId) {
            array_push($ids, ...$resolver->holders('crm.lead.view', Scope::branch($branchId)));
        }

        $users = User::query()->whereKey(array_values(array_unique($ids)))->where('status', User::STATUS_ACTIVE)->orderBy('name')->limit(500)->get(['id', 'name']);

        return response()->json(['data' => $users->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values()]);
    }

    /** CRM-16: count, value and weighted value per currency of the visible open opportunities by stage; won and lost totals. */
    public function summary(ReadRequest $request): JsonResponse
    {
        $pipeline = Pipeline::query()->findOrFail($request->validated('pipeline'));
        $companies = $this->access->companies($request->user(), 'crm.opportunity.view');
        abort_unless($companies === null || in_array($pipeline->company_id, $companies, true), 404);

        $base = fn () => $this->access->visible(Opportunity::query(), $request->user(), 'opportunity')->where('pipeline_id', $pipeline->id)->whereNull('archived_at');
        $open = $base()->where('status', Opportunity::OPEN)->groupBy('stage_id', 'currency')
            ->selectRaw('stage_id, currency, count(*) as count, sum(value_minor) as amount, sum(round(value_minor::numeric * probability / 100)) as weighted')->get();
        $won = $base()->where('status', Opportunity::WON)->groupBy('currency')->selectRaw('currency, count(*) as count, sum(value_minor) as amount')->get();
        // CRM-09: counts only for a reader whose field rules hide opportunity values.
        $hidden = $this->access->hidesValues($request->user());

        return response()->json(['data' => [
            'pipeline' => ['id' => $pipeline->id, 'name' => $pipeline->name],
            'stages' => Stage::query()->where('pipeline_id', $pipeline->id)->whereNull('archived_at')->orderBy('position')->get()->map(fn (Stage $stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'probability' => $stage->probability,
                'count' => (int) $open->where('stage_id', $stage->id)->sum('count'),
                'values' => $hidden ? null : $open->where('stage_id', $stage->id)->sortBy('currency')->map(fn ($row) => [
                    'currency' => $row->currency, 'amount_minor' => (string) $row->amount, 'weighted_minor' => (string) $row->weighted,
                ])->values()->all(),
            ])->values()->all(),
            'won' => ['count' => (int) $won->sum('count'), 'values' => $hidden ? null : $won->sortBy('currency')->map(fn ($row) => ['currency' => $row->currency, 'amount_minor' => (string) $row->amount])->values()->all()],
            'lost' => ['count' => $base()->where('status', Opportunity::LOST)->count()],
        ]]);
    }
}
