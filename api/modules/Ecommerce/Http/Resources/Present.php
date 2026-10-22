<?php

namespace Modules\Ecommerce\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Brick\Math\BigDecimal;
use Modules\Ecommerce\Delivery\ProofPhotos;
use Modules\Ecommerce\Models\Cashup;
use Modules\Ecommerce\Models\Conversation;
use Modules\Ecommerce\Models\Customer;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Message;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderEvent;
use Modules\Ecommerce\Models\OrderLine;
use Modules\Ecommerce\Models\Payment;
use Modules\Ecommerce\Models\Product;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Models\Zone;
use Modules\Ecommerce\Notifications\OrderNotices;
use Modules\Ecommerce\Orders\Returns;
use Modules\Ecommerce\Policies\DeliveryPolicy;
use Modules\Ecommerce\Shop\ShopUrls;

/**
 * How the back office sees the module's records: amounts as digit strings
 * in minor units next to their currency, times in ISO 8601 UTC, linked
 * records as `{id, name}`. Hashes and secrets are never shown.
 */
final class Present
{
    private static function at(mixed $value): ?string
    {
        return $value?->toIso8601String();
    }

    /** @return array{id: string, name: string}|null */
    private static function named(?string $id, string $model): ?array
    {
        if ($id === null) {
            return null;
        }

        $name = $model::query()->whereKey($id)->value('name');

        return ['id' => $id, 'name' => (string) $name];
    }

    public static function store(Store $store, bool $detail = false): array
    {
        $urls = app(ShopUrls::class);

        return [
            'id' => $store->id,
            'company' => self::named($store->company_id, Company::class),
            'branch' => self::named($store->branch_id, Branch::class),
            'location' => self::named($store->location_id, Location::class),
            'handle' => $store->handle,
            'name' => $store->name,
            'status' => $store->status,
            'currency' => $store->currency,
            'price_list_id' => $store->price_list_id,
            'delivery_enabled' => $store->delivery_enabled,
            'pickup_enabled' => $store->pickup_enabled,
            'pickup_location_ids' => $store->pickup_location_ids ?? [],
            'min_order_minor' => (string) $store->min_order_minor,
            'reservation_minutes' => $store->reservation_minutes,
            'safety_buffer' => (string) $store->safety_buffer,
            'contact_phone' => $store->contact_phone,
            'contact_email' => $store->contact_email,
            'whatsapp_number' => $store->whatsapp_number,
            'whatsapp_connected' => $store->whatsapp_token_hash !== null,
            // ECOM-44: order messages on WhatsApp need the platform's provider driver (owner input).
            'whatsapp_notifications' => app(OrderNotices::class)->whatsappAvailable() ? 'available' : 'not_configured',
            'delivery_tax_code_id' => $store->delivery_tax_code_id,
            'payment_methods' => $store->payment_methods ?? [],
            'archived_at' => self::at($store->archived_at),
            'share' => $detail ? ['url' => $urls->store($store), 'whatsapp' => ShopUrls::whatsapp($store->name.' '.$urls->store($store), $store->whatsapp_number)] : null,
            'created_at' => self::at($store->created_at),
        ];
    }

    public static function product(Product $product): array
    {
        $item = $product->relationLoaded('item') ? $product->item : Item::query()->find($product->item_id);

        return [
            'id' => $product->id,
            'store_id' => $product->store_id,
            'item' => $item === null ? null : ['id' => $item->id, 'code' => $item->code, 'name' => $item->name, 'type' => $item->type, 'archived' => $item->archived_at !== null],
            'slug' => $product->slug,
            'description' => $product->description,
            'published' => $product->published,
            'featured' => $product->featured,
            'position' => $product->position,
            'track_stock' => $product->track_stock,
            'in_stock' => $product->in_stock,
            'archived_at' => self::at($product->archived_at),
        ];
    }

    public static function zone(Zone $zone): array
    {
        return [
            'id' => $zone->id,
            'store_id' => $zone->store_id,
            'name' => $zone->name,
            'areas' => $zone->areas ?? [],
            'fee_minor' => (string) $zone->fee_minor,
            'free_over_minor' => $zone->free_over_minor === null ? null : (string) $zone->free_over_minor,
            'min_order_minor' => $zone->min_order_minor === null ? null : (string) $zone->min_order_minor,
            'eta' => $zone->eta,
            'active' => $zone->active,
            'position' => $zone->position,
            'archived_at' => self::at($zone->archived_at),
        ];
    }

    /** A row of the orders list. */
    public static function orderSummary(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'store' => self::named($order->store_id, Store::class),
            'company' => ['id' => $order->company_id],
            'location' => self::named($order->location_id, Location::class),
            'channel' => $order->channel,
            'fulfilment' => $order->fulfilment,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_kind' => $order->payment_kind,
            'contact_name' => $order->contact_name,
            'contact_phone' => $order->contact_phone,
            'currency' => $order->currency,
            'total_minor' => (string) $order->total_minor,
            'due_minor' => (string) $order->due_minor,
            'placed_at' => self::at($order->placed_at),
            'flags' => $order->flags ?? [],
        ];
    }

    public static function order(Order $order, ?User $viewer = null): array
    {
        $delivery = Delivery::query()->where('order_id', $order->id)->first();
        $returned = Returns::returnedQty($order);
        $left = fn (OrderLine $line) => $order->status === Order::DELIVERED
            ? rtrim(rtrim((string) BigDecimal::of((string) $line->qty)->minus($returned[$line->id] ?? '0')->toScale(3), '0'), '.') ?: '0' : '0';

        return [
            ...self::orderSummary($order),
            'customer_id' => $order->customer_id,
            'party_id' => $order->party_id,
            'contact_email' => $order->contact_email,
            'locale' => $order->locale,
            'zone' => self::named($order->zone_id, Zone::class),
            'pickup_location' => self::named($order->pickup_location_id, Location::class),
            'address' => $order->address,
            'totals' => [
                'subtotal_minor' => (string) $order->subtotal_minor, 'discount_minor' => (string) $order->discount_minor, 'tax_minor' => (string) $order->tax_minor,
                'delivery_fee_minor' => (string) $order->delivery_fee_minor, 'delivery_tax_minor' => (string) $order->delivery_tax_minor, 'total_minor' => (string) $order->total_minor,
                'loyalty_paid_minor' => (string) $order->loyalty_paid_minor, 'due_minor' => (string) $order->due_minor,
            ],
            'base' => ['currency' => $order->base_currency, 'total_minor' => (string) $order->base_total_minor, 'fx' => $order->fx?->jsonSerialize()],
            'coupon' => $order->coupon,
            'notes' => $order->notes,
            'lines' => OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->get()->map(fn (OrderLine $line) => [
                'id' => $line->id, 'line_no' => $line->line_no, 'product_id' => $line->product_id, 'item_id' => $line->item_id, 'name' => $line->name,
                'qty' => (string) $line->qty, 'unit_price_minor' => (string) $line->unit_price_minor, 'gross_minor' => (string) $line->gross_minor,
                'discount_minor' => (string) $line->discount_minor, 'tax_minor' => (string) $line->tax_minor, 'total_minor' => (string) $line->total_minor,
                'tax_rate' => $line->tax_rate === null ? null : (string) $line->tax_rate,
                // ECOM-42: what came back already, and what may still come back (a delivered order only).
                'returned_qty' => $returned[$line->id] ?? '0', 'returnable_qty' => $left($line),
            ])->values()->all(),
            'payments' => Payment::query()->where('order_id', $order->id)->orderBy('created_at')->orderBy('id')->get()->map(fn (Payment $p) => [
                'id' => $p->id, 'kind' => $p->kind, 'payment_method_id' => $p->payment_method_id, 'currency' => $p->currency, 'amount_minor' => (string) $p->amount_minor,
                'status' => $p->status, 'provider_reference' => $p->provider_reference, 'collected_at' => self::at($p->collected_at),
            ])->values()->all(),
            'history' => OrderEvent::query()->where('order_id', $order->id)->orderBy('at')->orderBy('id')->get()->map(fn (OrderEvent $e) => [
                'from' => $e->from, 'to' => $e->to, 'payment_status' => $e->payment_status, 'note' => $e->note, 'user' => self::named($e->user_id, User::class), 'at' => self::at($e->at),
            ])->values()->all(),
            'delivery' => $delivery === null ? null : self::delivery($delivery, false, $viewer),
            'returns' => Returns::present($order),
            'times' => [
                'paid_at' => self::at($order->paid_at), 'accepted_at' => self::at($order->accepted_at), 'picking_at' => self::at($order->picking_at),
                'ready_at' => self::at($order->ready_at), 'dispatched_at' => self::at($order->dispatched_at), 'delivered_at' => self::at($order->delivered_at),
                'cancelled_at' => self::at($order->cancelled_at), 'returned_at' => self::at($order->returned_at), 'reserved_until' => self::at($order->reserved_until),
            ],
            'cancel_reason' => $order->cancel_reason,
            'return_reason' => $order->return_reason,
            'refund_reference' => $order->refund_reference,
            'conversation_id' => $order->conversation_id,
        ];
    }

    /**
     * ECOM-40, ECOM-41: the position only for a viewer holding
     * `ecommerce.delivery.track` at the delivery's location; the proof photo
     * as a temporary link for the viewer.
     */
    public static function delivery(Delivery $delivery, bool $withOrder = true, ?User $viewer = null): array
    {
        $order = $withOrder ? Order::query()->find($delivery->order_id) : null;
        $track = $viewer !== null && app(DeliveryPolicy::class)->track($viewer, $delivery);

        return [
            'id' => $delivery->id,
            'order_id' => $delivery->order_id,
            'order' => $order === null ? null : [
                'number' => $order->number, 'status' => $order->status, 'contact_name' => $order->contact_name, 'contact_phone' => $order->contact_phone,
                'address' => $order->address, 'zone' => self::named($order->zone_id, Zone::class), 'currency' => $order->currency, 'total_minor' => (string) $order->total_minor,
                'payment_status' => $order->payment_status,
            ],
            'location' => self::named($delivery->location_id, Location::class),
            'rider' => self::named($delivery->rider_id, Rider::class),
            'status' => $delivery->status,
            'assigned_at' => self::at($delivery->assigned_at),
            'picked_up_at' => self::at($delivery->picked_up_at),
            'delivered_at' => self::at($delivery->delivered_at),
            'failed_at' => self::at($delivery->failed_at),
            'reason' => $delivery->reason,
            'proof_kind' => $delivery->proof_kind,
            'can_track' => $track,
            'last_location' => $track ? self::location($delivery) : null,
            'proof_photo' => $viewer === null ? null : app(ProofPhotos::class)->present($delivery, $viewer),
            'currency' => $delivery->currency,
            'cod_due_minor' => (string) $delivery->cod_due_minor,
            'cod_collected_minor' => $delivery->cod_collected_minor === null ? null : (string) $delivery->cod_collected_minor,
            'cashup_id' => $delivery->cashup_id,
            'flags' => $delivery->flags ?? [],
        ];
    }

    /** @return array{latitude: string, longitude: string, at: ?string}|null the delivery's latest reported position */
    public static function location(Delivery $delivery): ?array
    {
        if ($delivery->located_at === null || $delivery->last_latitude === null) {
            return null;
        }

        return ['latitude' => (string) $delivery->last_latitude, 'longitude' => (string) $delivery->last_longitude, 'at' => self::at($delivery->located_at)];
    }

    public static function rider(Rider $rider): array
    {
        return [
            'id' => $rider->id,
            'company' => self::named($rider->company_id, Company::class),
            'user' => self::named($rider->user_id, User::class),
            'employee_id' => $rider->employee_id,
            'name' => $rider->name,
            'phone' => $rider->phone,
            'vehicle' => $rider->vehicle,
            'active' => $rider->active,
            'archived_at' => self::at($rider->archived_at),
        ];
    }

    public static function cashup(Cashup $cashup): array
    {
        return [
            'id' => $cashup->id,
            'company' => self::named($cashup->company_id, Company::class),
            'rider' => self::named($cashup->rider_id, Rider::class),
            'currency' => $cashup->currency,
            'expected_minor' => (string) $cashup->expected_minor,
            'counted_minor' => (string) $cashup->counted_minor,
            'variance_minor' => (string) $cashup->variance_minor,
            'deliveries' => $cashup->deliveries,
            'note' => $cashup->note,
            'closed_by' => self::named($cashup->closed_by, User::class),
            'closed_at' => self::at($cashup->closed_at),
        ];
    }

    public static function conversation(Conversation $conversation, bool $messages = false): array
    {
        return [
            'id' => $conversation->id,
            'store' => self::named($conversation->store_id, Store::class),
            'phone' => $conversation->phone,
            'name' => $conversation->name,
            'status' => $conversation->status,
            'order' => $conversation->order_id === null ? null : ['id' => $conversation->order_id, 'number' => Order::query()->whereKey($conversation->order_id)->value('number')],
            'last_message_at' => self::at($conversation->last_message_at),
            'last_message' => $messages ? null : Message::query()->where('conversation_id', $conversation->id)->orderByDesc('at')->value('body'),
            'messages' => $messages ? Message::query()->where('conversation_id', $conversation->id)->orderBy('at')->orderBy('id')->get()->map(fn (Message $m) => [
                'id' => $m->id, 'direction' => $m->direction, 'body' => $m->body, 'status' => $m->status, 'sent_by' => self::named($m->sent_by, User::class), 'at' => self::at($m->at),
            ])->values()->all() : null,
        ];
    }

    public static function customer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'company' => self::named($customer->company_id, Company::class),
            'party_id' => $customer->party_id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'last_signed_in_at' => self::at($customer->last_signed_in_at),
            'orders' => Order::query()->where('customer_id', $customer->id)->count(),
            'created_at' => self::at($customer->created_at),
        ];
    }
}
