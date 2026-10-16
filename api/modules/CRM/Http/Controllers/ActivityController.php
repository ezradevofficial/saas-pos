<?php

namespace Modules\CRM\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\CRM\Activities\Activities;
use Modules\CRM\Activities\Emails;
use Modules\CRM\Http\Requests\ActivityRequest;
use Modules\CRM\Http\Requests\ListActivitiesRequest;
use Modules\CRM\Http\Requests\RecordActionRequest;
use Modules\CRM\Http\Requests\SendEmailRequest;
use Modules\CRM\Http\Resources\ActivityResource;
use Modules\CRM\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CRM-26 to CRM-29: activities, the calendar and logged emails. */
class ActivityController
{
    public function __construct(private readonly Activities $activities) {}

    public function index(ListActivitiesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Activity::query()->with(ActivityResource::RELATIONS);
        $request->applyFilters($query);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return ActivityResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(ActivityRequest $request): JsonResponse
    {
        return $this->detail($this->activities->create($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    public function update(ActivityRequest $request, Activity $crmActivity): ActivityResource
    {
        return $this->detail($this->activities->update($crmActivity, $request->validated(), $request->user()));
    }

    public function complete(RecordActionRequest $request, Activity $crmActivity): ActivityResource
    {
        return $this->detail($this->activities->complete($crmActivity, $request->validated('outcome')));
    }

    public function cancel(RecordActionRequest $request, Activity $crmActivity): ActivityResource
    {
        return $this->detail($this->activities->cancel($crmActivity));
    }

    public function reopen(RecordActionRequest $request, Activity $crmActivity): ActivityResource
    {
        return $this->detail($this->activities->reopen($crmActivity));
    }

    public function email(SendEmailRequest $request, Emails $emails): JsonResponse
    {
        return $this->detail($emails->send($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    private function detail(Activity $activity): ActivityResource
    {
        return ActivityResource::make($activity->refresh()->load(ActivityResource::RELATIONS));
    }
}
