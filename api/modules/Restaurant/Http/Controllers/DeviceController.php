<?php

namespace Modules\Restaurant\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Http\JsonResponse;
use Modules\POS\Sync\DevicePlace;
use Modules\Restaurant\Http\Requests\DeviceTicketStatusRequest;
use Modules\Restaurant\Http\Requests\SyncOrdersRequest;
use Modules\Restaurant\Http\Requests\TicketPermission;
use Modules\Restaurant\Models\Ticket;
use Modules\Restaurant\Orders\OrderView;
use Modules\Restaurant\Orders\Tickets;
use Modules\Restaurant\Support\Errors;
use Modules\Restaurant\Sync\OrderUploads;

/**
 * REST-17, REST-09: what a paired till sends (device token only; place from
 * the device). Orders answer one result per record: 200 when anything is
 * stored, 422 `upload_rejected` when nothing is.
 */
class DeviceController
{
    public function sync(SyncOrdersRequest $request, OrderUploads $uploads): JsonResponse
    {
        $results = $uploads->upload(DevicePlace::of($request->user()), $request->validated('orders'));

        if (collect($results)->every(fn (array $r) => $r['status'] === 'rejected')) {
            return response()->json(['message' => __('restaurant.errors.upload_rejected'), 'code' => 'upload_rejected', 'results' => $results], 422);
        }

        return response()->json(['results' => $results]);
    }

    public function ticketStatus(DeviceTicketStatusRequest $request, Ticket $restTicket, Tickets $tickets, OrderView $view, ScopeResolver $resolver): JsonResponse
    {
        $place = DevicePlace::of($request->user());

        if ($restTicket->location_id !== $place->location->id) {
            throw Errors::make(404, 'ticket_unknown');
        }

        $person = User::query()->find($request->validated('user_id'));

        if ($person === null) {
            throw Errors::make(422, 'user_unknown', field: 'user_id');
        }

        if (! $person->isActive() || ! $resolver->can($person, TicketPermission::for($person, $request->validated('status'), Scope::location($place->location->id)), Scope::location($place->location->id))) {
            throw Errors::make(403, 'ticket_not_permitted');
        }

        $tickets->setStatus($restTicket, (string) $request->validated('status'), $person);

        return response()->json(['data' => $view->ticket($restTicket->refresh())]);
    }
}
