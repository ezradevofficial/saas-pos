<?php

// Procurement module settings (docs/modules/procurement.md).
return [
    'portal' => [
        // PRC-07: how long a supplier portal session lasts.
        'session_hours' => (int) env('PROCUREMENT_PORTAL_SESSION_HOURS', 8),
        // PRC-23: portal requests per minute per session (or IP), sign-ins and
        // registrations per minute per IP, registrations per hour per email.
        'per_minute' => 30,
        'per_ip_per_minute' => 120,
        // PRC-07: a session slides with use up to this many days; a link stays usable this long after the deadline (read-only).
        'session_max_days' => 7,
        'link_days_after_deadline' => 90,
        'auth_per_minute' => 10,
        'registrations_per_hour' => 5,
    ],
];
