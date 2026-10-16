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
    ],

    'whatsapp' => [
        // ECOM-24: the WhatsApp Business driver (`fake` locally and in tests; none: WhatsApp off).
        'driver' => env('ECOMMERCE_WHATSAPP_DRIVER', $development ? 'fake' : null),
        // NFR-06: the fake driver accepts messages nobody sent; never in a real environment.
        'allow_fake' => (bool) env('ECOMMERCE_WHATSAPP_ALLOW_FAKE', $development),
        'fake_secret' => env('ECOMMERCE_WHATSAPP_FAKE_SECRET', 'fake-whatsapp-secret'),
        'per_minute' => (int) env('ECOMMERCE_WHATSAPP_PER_MINUTE', 120),
    ],

    'rider' => [
        // ECOM-28: status updates in one upload, and the largest proof photo (kilobytes).
        'max_updates' => 100,
        'max_photo_kb' => 4096,
    ],
];
