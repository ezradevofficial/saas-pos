<?php

namespace Modules\Salon\Http\Controllers;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Http\JsonResponse;
use Modules\Salon\Http\Requests\ActionRequest;
use Modules\Salon\Http\Requests\SeatQueueRequest;
use Modules\Salon\Http\Requests\StoreQueueRequest;
use Modules\Salon\Http\Requests\ViewRequest;
use Modules\Salon\Http\Resources\Views;
use Modules\Salon\Models\QueueEntry;
use Modules\Salon\Queue\Queue;
use Modules\Salon\Support\Errors;
use Modules\Salon\Support\Places;

/** SAL-12: the walk-in queue. */
class QueueController
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Views $views,
        private readonly Places $places,
        private readonly ScopeResolver $resolver,
    ) {}

    public function index(ViewRequest $request): JsonResponse
    {
        $query = QueueEntry::query()->where('status', QueueEntry::WAITING)->orderBy('joined_at')->orderBy('id');
        $this->places->filterBranches($query, $request->user(), $request->permission());
        $request->filled('branch_id') && $query->where('branch_id', $request->input('branch_id'));
        $entries = $query->get();

        return response()->json(['data' => $entries->isEmpty() ? [] : $this->views->queue($entries, $entries->first()->branch_id)]);
    }

    public function store(StoreQueueRequest $request): JsonResponse
    {
        $entry = $this->queue->join($request->validated(), $request->user());

        return response()->json(['data' => $this->views->queue(collect([$entry]), $entry->branch_id)[0]], 201);
    }

    /** Seating books a walk-in appointment: it needs the right to book there as well as to run the queue. */
    public function seat(SeatQueueRequest $request, QueueEntry $salonQueue): JsonResponse
    {
        if (! $this->resolver->can($request->user(), 'salon.appointment.create', Scope::branch($salonQueue->branch_id))) {
            throw Errors::make(403, 'not_permitted');
        }

        $seated = $this->queue->seat($salonQueue, (string) $request->input('staff_id'), $request->input('resource_id'), $request->user());

        return response()->json(['data' => [
            'entry' => $this->views->queue(collect([$seated['entry']]), $seated['entry']->branch_id)[0],
            'appointment' => $this->views->appointments(collect([$seated['appointment']]), $request)[0],
        ]]);
    }

    public function leave(ActionRequest $request, QueueEntry $salonQueue): JsonResponse
    {
        $entry = $this->queue->leave($salonQueue);

        return response()->json(['data' => $this->views->queue(collect([$entry]), $entry->branch_id)[0]]);
    }
}
