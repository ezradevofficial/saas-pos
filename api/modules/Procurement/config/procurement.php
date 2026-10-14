<?php

// Procurement module settings (docs/modules/procurement.md).
return [
    'portal' => [
        // PRC-07: how long a supplier portal session lasts.
        'session_hours' => (int) env('PROCUREMENT_PORTAL_SESSION_HOURS', 8),
        // PRC-23: portal requests per minute per session (or IP), sign-ins and
        // registrations per minute per IP, registrations per hour per email.
        'per_minute' => 30,
        'auth_per_minute' => 10,
        'registrations_per_hour' => 5,
    ],
];
