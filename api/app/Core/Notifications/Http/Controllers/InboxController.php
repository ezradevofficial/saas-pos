<?php

namespace App\Core\Notifications\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Notifications\Http\Requests\InboxActionRequest;
use App\Core\Notifications\Http\Requests\ListInboxRequest;
use App\Core\Notifications\Http\Resources\InAppNotificationResource;
use App\Core\Notifications\Models\InAppNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * NOT-01: the signed-in user's in-app notifications (the bell and the
 * inbox). Every query is the user's own rows in the current tenant: another
 * user's notification, or another tenant's, is not found.
 */
class InboxController
{
    public function index(ListInboxRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $this->mine($request);

        match ($request->validated('status', 'active')) {
            'active' => $query->whereNull('archived_at'),
            'unread' => $query->whereNull('archived_at')->whereNull('read_at'),
            'archived' => $query->whereNotNull('archived_at'),
            default => $query,
        };

        $request->applySort($request->applySearch($query, ['subject' => 'subject', 'body' => 'body']));

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return InAppNotificationResource::collection($query->paginate($request->perPage())->withQueryString())
            ->additional(['meta' => ['unread' => $this->unread($request)]]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => ['unread' => $this->unread($request)]]);
    }

    public function read(InboxActionRequest $request, string $notification): InAppNotificationResource
    {
        $model = $this->find($request, $notification);

        if ($model->read_at === null) {
            $model->forceFill(['read_at' => now()])->save();
        }

        return InAppNotificationResource::make($model);
    }

    public function readAll(InboxActionRequest $request): JsonResponse
    {
        $updated = $this->mine($request)->whereNull('read_at')->whereNull('archived_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return new JsonResponse(['data' => ['updated' => $updated, 'unread' => 0]]);
    }

    public function archive(InboxActionRequest $request, string $notification): InAppNotificationResource
    {
        $model = $this->find($request, $notification);

        if ($model->archived_at === null) {
            $model->forceFill(['archived_at' => now(), 'read_at' => $model->read_at ?? now()])->save();
        }

        return InAppNotificationResource::make($model);
    }

    /** @return Builder<InAppNotification> */
    private function mine(Request $request): Builder
    {
        return InAppNotification::query()->where('user_id', $request->user()->getKey());
    }

    private function unread(Request $request): int
    {
        return $this->mine($request)->whereNull('read_at')->whereNull('archived_at')->count();
    }

    private function find(Request $request, string $id): InAppNotification
    {
        return $this->mine($request)->whereKey($id)->firstOrFail();
    }
}
