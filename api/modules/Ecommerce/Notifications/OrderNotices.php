<?php

namespace Modules\Ecommerce\Notifications;

use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\NotificationAddress;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Support\Amounts;

/**
 * ECOM-20, NOT-02, ADR 009: what the customer hears about an order goes
 * through the core notifier as system messages to the order's contact
 * (SMS to the phone, email when given), in the language the order was
 * placed in. In email-only mode the SMS is skipped (`sms_unavailable`) and
 * goes by WhatsApp where configured; the email goes as usual. The tracking link and the delivery code are secrets: they
 * reach the message at hand-over only and are never stored (the order
 * keeps their hashes). A transport failure never fails the order.
 */
final class OrderNotices
{
    public const PLACED = 'ecommerce.order.placed';

    public const PAID = 'ecommerce.order.paid';

    public const ACCEPTED = 'ecommerce.order.accepted';

    public const READY = 'ecommerce.order.ready';

    public const DISPATCHED = 'ecommerce.order.dispatched';

    public const DELIVERED = 'ecommerce.order.delivered';

    public const CANCELLED = 'ecommerce.order.cancelled';

    public const TYPES = [
        self::PLACED => 'placed', self::PAID => 'paid', self::ACCEPTED => 'accepted', self::READY => 'ready',
        self::DISPATCHED => 'dispatched', self::DELIVERED => 'delivered', self::CANCELLED => 'cancelled',
    ];

    public function __construct(private readonly Notifier $notifier) {}

    public static function register(EventTypes $types): void
    {
        foreach (self::TYPES as $key => $name) {
            $types->register(new EventType(
                key: $key,
                placeholders: [
                    'store_name' => 'Duka Online', 'order_number' => 'WEB-NBO-2026-00012', 'total' => 'KES 2,450.00',
                    'tracking_url' => 'https://shop.example.com/shop/duka/orders/WEB-NBO-2026-00012?t=…', 'delivery_code' => '482913',
                ],
                defaultChannels: [Channels::SMS, Channels::EMAIL],
                // NOT-01: WhatsApp only as the fallback for SMS in email-only mode (Notifier).
                channels: [Channels::SMS, Channels::EMAIL, Channels::WHATSAPP],
                langKey: "ecommerce.notifications.{$name}",
                system: true,
                secrets: ['tracking_url', 'delivery_code'],
                contactsOnly: true,
            ));
        }
    }

    /** @param array<string, string> $secrets tracking_url, delivery_code */
    public function send(string $type, Order $order, Store $store, array $secrets = []): void
    {
        $addresses = [];
        $locale = in_array($order->locale, Channels::LOCALES, true) ? $order->locale : 'en';

        if ($order->contact_phone !== '') {
            $addresses[] = new NotificationAddress(Channels::SMS, $order->contact_phone, $order->contact_name, $locale);
        }

        if ($order->contact_email !== null && $order->contact_email !== '') {
            $addresses[] = new NotificationAddress(Channels::EMAIL, $order->contact_email, $order->contact_name, $locale);
        }

        if ($addresses === []) {
            return;
        }

        rescue(fn () => $this->notifier->send(new NotificationEvent(
            $type,
            [],
            ['store_name' => $store->name, 'order_number' => $order->number, 'total' => Amounts::show($order->total_minor, $order->currency)],
            null,
            $addresses,
            ['tracking_url' => $secrets['tracking_url'] ?? '', 'delivery_code' => $secrets['delivery_code'] ?? ''],
        )), report: true);
    }
}
