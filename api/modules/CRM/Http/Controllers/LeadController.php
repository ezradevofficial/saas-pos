<?php

namespace Modules\CRM\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\CRM\Http\Requests\LeadRequest;
use Modules\CRM\Http\Requests\ListLeadsRequest;
use Modules\CRM\Http\Requests\RecordActionRequest;
use Modules\CRM\Http\Requests\ShowRecordRequest;
use Modules\CRM\Http\Resources\LeadResource;
use Modules\CRM\Leads\Conversion;
use Modules\CRM\Leads\Leads;
use Modules\CRM\Models\Lead;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CRM-05 to CRM-13: leads, their assignment and conversion. */
class LeadController
{
    public function __construct(private readonly Leads $leads) {}

    public function index(ListLeadsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Lead::query()->with(LeadResource::RELATIONS);
        $request->applyFilters($query);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return LeadResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowRecordRequest $request, Lead $crmLead): LeadResource
    {
        return $this->detail($crmLead);
    }

    public function store(LeadRequest $request): JsonResponse
    {
        $channel = $request->validated('channel', 'api');

        return $this->detail($this->leads->create($request->validated(), $request->user(), $channel))->response()->setStatusCode(201);
    }

    public function update(LeadRequest $request, Lead $crmLead): LeadResource
    {
        return $this->detail($this->leads->update($crmLead, $request->validated(), $request->user()));
    }

    public function assign(RecordActionRequest $request, Lead $crmLead): LeadResource
    {
        return $this->detail($this->leads->assign($crmLead, $request->validated(), $request->user()));
    }

    public function convert(RecordActionRequest $request, Lead $crmLead, Conversion $conversion): LeadResource
    {
        return $this->detail($conversion->convert($crmLead, $request->validated(), $request->user()));
    }

    public function disqualify(RecordActionRequest $request, Lead $crmLead): LeadResource
    {
        return $this->detail($this->leads->disqualify($crmLead, (string) $request->validated('reason')));
    }

    public function archive(RecordActionRequest $request, Lead $crmLead): LeadResource
    {
        return $this->detail($this->leads->archive($crmLead, true));
    }

    public function restore(RecordActionRequest $request, Lead $crmLead): LeadResource
    {
        return $this->detail($this->leads->archive($crmLead, false));
    }

    private function detail(Lead $lead): LeadResource
    {
        return LeadResource::make($lead->refresh()->load(LeadResource::RELATIONS));
    }
}
