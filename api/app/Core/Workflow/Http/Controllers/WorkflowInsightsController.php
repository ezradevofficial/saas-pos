<?php

namespace App\Core\Workflow\Http\Controllers;

use App\Core\Workflow\Http\Requests\WorkflowInsightsRequest;
use App\Core\Workflow\Insights\WorkflowInsights;
use Illuminate\Http\JsonResponse;

/**
 * WF-10: stage volumes and bottlenecks of the live flows (WorkflowInsights),
 * one entry per flow with its stages in flow order. `meta` gives the
 * period as asked (days in `timezone`) and that times are elapsed time.
 */
class WorkflowInsightsController
{
    public function index(WorkflowInsightsRequest $request, WorkflowInsights $insights): JsonResponse
    {
        [$from, $to] = $request->period();
        $zone = $request->timezone();

        return new JsonResponse([
            'data' => $insights->compute($request->user(), $request->validated('type'), $request->validated('company'), $from, $to),
            'meta' => [
                'from' => $from->setTimezone($zone)->toDateString(),
                'to' => $to->setTimezone($zone)->toDateString(),
                'timezone' => $zone,
                'time_basis' => 'elapsed',
                'types' => array_map(fn ($type) => ['key' => $type->key(), 'label' => __($type->label())], $request->types()),
            ],
        ]);
    }
}
