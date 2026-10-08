<?php

namespace App\Core\Notifications\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Notifications\Http\Requests\ListDeliveriesRequest;
use App\Core\Notifications\Http\Resources\DeliveryResource;
use App\Core\Notifications\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** NOT-06: the tenant's delivery log for admins, listed, searched, sorted and exported (EXP-01). */
class DeliveryController
{
    public function index(ListDeliveriesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = NotificationDelivery::query()->with('user');
        $status = $request->validated('status', 'all');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($request->validated('channel') !== null) {
            $query->where('channel', $request->validated('channel'));
        }

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(function (Builder $q) use ($like) {
                $q->whereRaw('notification_deliveries.recipient ilike ?', [$like])
                    ->orWhereRaw('notification_deliveries.subject ilike ?', [$like])
                    ->orWhereRaw('notification_deliveries.event_type ilike ?', [$like])
                    ->orWhereHas('user', fn (Builder $user) => $user->whereRaw('users.name ilike ?', [$like]));
            });
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return DeliveryResource::collection($query->paginate($request->perPage())->withQueryString());
    }
}
