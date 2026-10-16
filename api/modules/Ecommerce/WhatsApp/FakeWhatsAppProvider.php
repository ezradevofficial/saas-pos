<?php

namespace Modules\Ecommerce\WhatsApp;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\Store;

/**
 * ECOM-24, NFR-06: a WhatsApp provider for local work and tests (never in a
 * real environment: `ecommerce.whatsapp.allow_fake`). A request is genuine
 * when its `X-Fake-Signature` is the HMAC-SHA256 of the body with
 * `ecommerce.whatsapp.fake_secret` (no default: unset, nothing verifies); the body is
 * `{messages: [{id, from, name?, text, at?}]}`. Replies are kept in
 * memory ($sent) and always succeed, except to a number ending in 000.
 */
class FakeWhatsAppProvider implements WhatsAppProvider
{
    /** @var list<array{store_id: string, to: string, text: string, id: string}> */
    public static array $sent = [];

    public function name(): string
    {
        return 'fake';
    }

    public function verify(Request $request, Store $store): bool
    {
        $signature = (string) $request->headers->get('X-Fake-Signature', '');
        $expected = self::sign($request->getContent());

        return $signature !== '' && $expected !== '' && hash_equals($expected, $signature);
    }

    public function messages(Request $request): array
    {
        $messages = [];

        foreach ((array) $request->json('messages', []) as $message) {
            if (! is_array($message) || ! is_string($message['id'] ?? null) || ! is_string($message['from'] ?? null) || ! is_string($message['text'] ?? null)) {
                continue;
            }

            $messages[] = [
                'id' => mb_substr($message['id'], 0, 120),
                'from' => $message['from'],
                'name' => is_string($message['name'] ?? null) ? mb_substr($message['name'], 0, 160) : null,
                'text' => mb_substr($message['text'], 0, 4000),
                'at' => is_string($message['at'] ?? null) ? $message['at'] : CarbonImmutable::now()->toIso8601String(),
            ];
        }

        return $messages;
    }

    public function send(Store $store, string $to, string $text): array
    {
        if (str_ends_with($to, '000')) {
            return ['id' => null, 'sent' => false, 'error' => 'fake_refused'];
        }

        $id = 'fake-out-'.Str::uuid7();
        self::$sent[] = ['store_id' => $store->id, 'to' => $to, 'text' => $text, 'id' => $id];

        return ['id' => $id, 'sent' => true, 'error' => null];
    }

    /** The signature of $body; empty (nothing verifies) while no secret is configured: there is no default. */
    public static function sign(string $body): string
    {
        $secret = (string) config('ecommerce.whatsapp.fake_secret');

        return $secret === '' ? '' : hash_hmac('sha256', $body, $secret);
    }
}
