<?php

namespace Modules\CRM\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\CRM\Http\Requests\ListOpportunitiesRequest;
use Modules\CRM\Http\Requests\OpportunityRequest;
use Modules\CRM\Http\Requests\RecordActionRequest;
use Modules\CRM\Http\Requests\ShowRecordRequest;
use Modules\CRM\Http\Resources\ActivityResource;
use Modules\CRM\Http\Resources\OpportunityResource;
use Modules\CRM\Http\Resources\QuoteResource;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\Quote;
use Modules\CRM\Opportunities\Opportunities;
use Modules\CRM\Support\CrmAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CRM-14 to CRM-16: opportunities, stage moves and closing. */
class OpportunityController
{
    public function __construct(
        private readonly Opportunities $opportunities,
        private readonly CrmAccess $access,
    ) {}

    public function index(ListOpportunitiesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Opportunity::query()->with(OpportunityResource::RELATIONS);
        $request->applyFilters($query);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return OpportunityResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowRecordRequest $request, Opportunity $crmOpportunity): OpportunityResource
    {
        return $this->detail($request, $crmOpportunity);
    }

    public function store(OpportunityRequest $request): JsonResponse
    {
        return $this->detail($request, $this->opportunities->create($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    public function update(OpportunityRequest $request, Opportunity $crmOpportunity): OpportunityResource
    {
        return $this->detail($request, $this->opportunities->update($crmOpportunity, $request->validated()));
    }

    public function win(RecordActionRequest $request, Opportunity $crmOpportunity): OpportunityResource
    {
        return $this->detail($request, $this->opportunities->win($crmOpportunity, $request->validated(), $request->user()));
    }

    public function lose(RecordActionRequest $request, Opportunity $crmOpportunity): OpportunityResource
    {
        return $this->detail($request, $this->opportunities->lose($crmOpportunity, $request->validated(), $request->user()));
    }

    public function reopen(RecordActionRequest $request, Opportunity $crmOpportunity): OpportunityResource
    {
        return $this->detail($request, $this->opportunities->reopen($crmOpportunity));
    }

    public function archive(RecordActionRequest $request, Opportunity $crmOpportunity): OpportunityResource
    {
        return $this->detail($request, $this->opportunities->archive($crmOpportunity, true));
    }

    public function restore(RecordActionRequest $request, Opportunity $crmOpportunity): OpportunityResource
    {
        return $this->detail($request, $this->opportunities->archive($crmOpportunity, false));
    }

    /** The detail adds the opportunity's activities and quotes the reader sees (CRM-08). */
    private function detail(Request $request, Opportunity $opportunity): OpportunityResource
    {
        $opportunity = $opportunity->refresh()->load(OpportunityResource::RELATIONS);
        $user = $request->user();
        $activities = $this->access->visible(Activity::query(), $user, 'activity')->where('opportunity_id', $opportunity->id)
            ->with(ActivityResource::RELATIONS)->orderByDesc('starts_at')->limit(100)->get();
        $quotes = $this->access->visible(Quote::query(), $user, 'quote')->where('opportunity_id', $opportunity->id)
            ->with(QuoteResource::RELATIONS)->orderByDesc('created_at')->limit(100)->get();

        return OpportunityResource::make($opportunity)->detail([
            'activities' => ActivityResource::collection($activities)->resolve($request),
            'quotes' => QuoteResource::collection($quotes)->resolve($request),
        ]);
    }
}
