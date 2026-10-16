<?php

// HOOK-01: webhook subscriptions and deliveries (docs/adr/021-webhooks.md).
return [
    // The queue fan-outs and deliveries run on. Horizon's
    // `supervisor-webhooks` works it on the `redis-webhooks` connection, so a
    // slow receiver never delays notifications, automation or fiscal work.
    'queue' => env('WEBHOOKS_QUEUE', 'webhooks'),

    // Seconds for the whole call (connecting gets at most connect_timeout);
    // DNS lookups get automation.dns_timeout on top.
    'timeout' => (int) env('WEBHOOKS_TIMEOUT', 10),
    'connect_timeout' => 5,

    // Bytes of the answer kept in the delivery log.
    'response_bytes' => 1024,

    // Seconds to wait before each retry: 1 min, 5 min, 30 min, 2 h, 6 h,
    // 24 h; after the last one the delivery is failed (7 attempts in all).
    'backoff' => [60, 300, 1800, 7200, 21600, 86400],

    // A subscription whose deliveries fail for good this many times in a
    // row is switched off and its managers are notified.
    'disable_after' => (int) env('WEBHOOKS_DISABLE_AFTER', 10),

    // Hours the previous signing secret keeps signing after a rotation.
    'rotation_overlap_hours' => 24,

    // Days the delivery log is kept (webhooks:sweep deletes older rows).
    'retention_days' => 30,

    // A delivery left pending or sending this many minutes, or retrying
    // this long past its due time, is queued again (a worker died).
    'stuck_minutes' => 15,

    // Test events and replays per user and minute (each calls out to an endpoint).
    'sends_per_minute' => 20,

    // Subscriptions per tenant (archived ones not counted).
    'max_subscriptions' => 25,

    // Local development only (APP_ENV=local): hosts that may be called
    // over plain HTTP and on a private or loopback address, e.g.
    // "127.0.0.1,localhost". Ignored in every other environment.
    'local_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('WEBHOOKS_LOCAL_HOSTS', ''))))),
];
