<?php

// NOT-01..NOT-06: the core notification service (App\Core\Notifications).
return [
    // Provider adapter per driven channel. Unset: `fake` in local and
    // testing, unavailable elsewhere (deliveries skipped with the reason
    // channel_unavailable). `none` switches a channel off. Real providers
    // are added per country once the owner has credentials; `fake` is
    // refused outside local and testing (EnvironmentGuard).
    'drivers' => [
        'push' => env('NOTIFICATIONS_PUSH_DRIVER'),
        'sms' => env('NOTIFICATIONS_SMS_DRIVER'),
        'whatsapp' => env('NOTIFICATIONS_WHATSAPP_DRIVER'),
    ],

    // NOT-06: attempts per delivery, and the wait (seconds) before the
    // second and third attempt.
    'attempts' => 3,
    'backoff' => [60, 300],

    // The queue SendDelivery, SendDigests and the approval timers run on
    // (its own Horizon supervisor, config/horizon.php).
    'queue' => env('NOTIFICATIONS_QUEUE', 'notifications'),

    // NOT-05: digests go out at this local hour (the recipient's time
    // zone, see RecipientTimezone): daily every day, weekly on Mondays.
    'digest_hour' => 7,
];
