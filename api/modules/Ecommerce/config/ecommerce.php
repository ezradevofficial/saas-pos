<?php

$development = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

// E-commerce and delivery (docs/modules/ecommerce.md). Credentials of real
// providers are owner input (open questions 1 and 2) and live in the
// environment, never here.
return [
    'shop' => [
        // ECOM-07: storefront requests a minute per address (reads, checkout, sign-in codes).
        'per_ip_per_minute' => (int) env('ECOMMERCE_SHOP_PER_MINUTE', 120),
        'checkout_per_ip_per_minute' => (int) env('ECOMMERCE_CHECKOUT_PER_MINUTE', 10),
        'codes_per_ip_per_minute' => (int) env('ECOMMERCE_CODES_PER_MINUTE', 10),
        // ECOM-08: sign-in code checks a minute per address (a limiter of their own).
        'verify_per_ip_per_minute' => (int) env('ECOMMERCE_VERIFY_PER_MINUTE', 10),
        // ECOM-08: codes a store sends in an hour, across all contacts (SMS and email cost money).
        'codes_per_store_per_hour' => (int) env('ECOMMERCE_CODES_PER_STORE_PER_HOUR', 300),
        // ECOM-08: after this many codes from one address in an hour, a proof-of-work challenge
        // (sha256(nonce:solution) starting with `challenge_difficulty` zeros) comes first.
        'challenge_after' => (int) env('ECOMMERCE_CHALLENGE_AFTER', 3),
        'challenge_difficulty' => (int) env('ECOMMERCE_CHALLENGE_DIFFICULTY', 4),
        // ECOM-33: failed gift card lookups an hour, per address and per store.
        'gift_card_failures_per_ip_per_hour' => (int) env('ECOMMERCE_GIFT_CARD_FAILURES_PER_IP', 10),
        'gift_card_failures_per_store_per_hour' => (int) env('ECOMMERCE_GIFT_CARD_FAILURES_PER_STORE', 100),
    ],

    'whatsapp' => [
        // ECOM-24: the WhatsApp Business driver (`fake` locally and in tests; none: WhatsApp off).
        'driver' => env('ECOMMERCE_WHATSAPP_DRIVER', $development ? 'fake' : null),
        // NFR-06: the fake driver accepts messages nobody sent; never in a real environment.
        'allow_fake' => (bool) env('ECOMMERCE_WHATSAPP_ALLOW_FAKE', $development),
        // No default: without a secret the fake driver verifies nothing.
        'fake_secret' => env('ECOMMERCE_WHATSAPP_FAKE_SECRET'),
        // Webhook calls a minute per store token, and per calling address.
        'per_minute' => (int) env('ECOMMERCE_WHATSAPP_PER_MINUTE', 120),
        'per_ip_per_minute' => (int) env('ECOMMERCE_WHATSAPP_PER_IP_PER_MINUTE', 300),
    ],

    'rider' => [
        // ECOM-28: status updates in one upload, and the largest proof photo (kilobytes).
        'max_updates' => 100,
        'max_photo_kb' => 4096,
    ],
];
