<?php

namespace Modules\Ecommerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ecommerce\Http\Requests\BackOfficeOrderRequest;
use Modules\Ecommerce\Http\Requests\ListRequest;
use Modules\Ecommerce\Http\Requests\ReplyRequest;
use Modules\Ecommerce\Http\Resources\Present;
use Modules\Ecommerce\Models\Conversation;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Support\Access;
use Modules\Ecommerce\Support\EcomError;
use Modules\Ecommerce\WhatsApp\Inbox;

/**
 * ECOM-25: the WhatsApp inbox of the stores at the user's locations
 * (`ecommerce.whatsapp.view`); replies (`.reply`) go out through the
 * provider; a chat becomes an order (`ecommerce.order.create`, channel
 * `whatsapp`) through the same pricing and placement as any order.
 */
class ConversationController
{
    public function __construct(private readonly Access $access, private readonly Inbox $inbox) {}

    public function index(ListRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'ecommerce.whatsapp.view'), 403);
        $stores = $this->access->byLocation(Store::query(), $request->user(), 'ecommerce.whatsapp.view')->select('id');
        $status = $request->validated('status', 'open');
        $query = Conversation::query()->whereIn('store_id', $stores)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->filled('store'), fn ($q) => $q->where('store_id', $request->validated('store')))
            ->orderByDesc('last_message_at');
        $page = $query->paginate($request->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (Conversation $c) => Present::conversation($c))->values(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(ListRequest $request, Conversation $ecomConversation): JsonResponse
    {
        $this->visible($request, $ecomConversation);

        return response()->json(['data' => Present::conversation($ecomConversation, true)]);
    }

    public function reply(ReplyRequest $request, Conversation $ecomConversation): JsonResponse
    {
        $store = $this->visible($request, $ecomConversation);
        $this->access->assertAtLocation($request->user(), 'ecommerce.whatsapp.reply', $store->location_id);
        $this->inbox->reply($ecomConversation, (string) $request->validated('text'), $request->user());

        return response()->json(['data' => Present::conversation($ecomConversation->refresh(), true)], 201);
    }

    public function close(ListRequest $request, Conversation $ecomConversation): JsonResponse
    {
        $store = $this->visible($request, $ecomConversation);
        $this->access->assertAtLocation($request->user(), 'ecommerce.whatsapp.reply', $store->location_id);
        $ecomConversation->forceFill(['status' => 'closed'])->save();

        return response()->json(['data' => Present::conversation($ecomConversation->refresh(), true)]);
    }

    /** ECOM-25: the chat as an order (its phone the contact's, channel `whatsapp`). */
    public function order(BackOfficeOrderRequest $request, Conversation $ecomConversation): JsonResponse
    {
        $store = $this->visible($request, $ecomConversation);
        $this->access->assertAtLocation($request->user(), 'ecommerce.order.create', $store->location_id);

        if ($ecomConversation->status === 'converted' && $ecomConversation->order_id !== null) {
            throw EcomError::make('conversation_converted', status: 409);
        }

        return app(OrderController::class)->place($request, $store, 'whatsapp', $ecomConversation);
    }

    private function visible(Request $request, Conversation $conversation): Store
    {
        $store = Store::query()->findOrFail($conversation->store_id);
        abort_unless($this->access->atLocation($request->user(), 'ecommerce.whatsapp.view', $store->location_id), 404);

        return $store;
    }
}
