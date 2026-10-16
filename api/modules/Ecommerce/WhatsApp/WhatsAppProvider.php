<?php

namespace Modules\Ecommerce\WhatsApp;

use Illuminate\Http\Request;
use Modules\Ecommerce\Models\Store;

/**
 * ECOM-24: a WhatsApp Business provider behind one interface (decision 4;
 * the provider is owner input: Meta's Cloud API or a BSP). A driver
 * verifies an inbound webhook request, reads its messages, and sends a
 * text reply. Only the `fake` driver ships.
 */
interface WhatsAppProvider
{
    public function name(): string;

    /** Whether $request really comes from the provider for $store (signature, token). */
    public function verify(Request $request, Store $store): bool;

    /**
     * The text messages the request carries (others are ignored).
     *
     * @return list<array{id: string, from: string, name: ?string, text: string, at: string}>
     */
    public function messages(Request $request): array;

    /**
     * Send $text to $to (E.164) from the store's number.
     *
     * @return array{id: ?string, sent: bool, error: ?string}
     */
    public function send(Store $store, string $to, string $text): array;
}
