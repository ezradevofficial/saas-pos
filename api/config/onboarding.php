<?php

// Self-onboarding (ONB-01..ONB-08, docs/adr/017-onboarding.md).
return [
    // ONB-04: the free trial's length, from the day modules are picked.
    'trial_days' => (int) env('ONBOARDING_TRIAL_DAYS', 14),

    // ONB-01: brand names no tenant subdomain may take (folded for look-alike
    // characters before the check); a sign-up named after one gets a neutral
    // subdomain instead. The platform's own names come from PLATFORM_BRAND_NAMES.
    'protected_slugs' => array_values(array_filter(array_map('trim', [
        'safaricom', 'mpesa', 'airtel', 'orange', 'vodacom', 'equity', 'kcb', 'kra', 'dgi', 'rawbank',
        ...explode(',', (string) env('PLATFORM_BRAND_NAMES', '')),
    ]))),

    // ONB-01: sign-up limits, on top of the per-IP limit: per email or phone
    // number, and across the whole platform.
    'sign_ups_per_destination_per_hour' => (int) env('SIGN_UPS_PER_DESTINATION_PER_HOUR', 3),
    'sign_ups_per_hour' => (int) env('SIGN_UPS_PER_HOUR', 200),

    // ONB-01: a self sign-up whose owner never verified is closed after this many hours.
    'pending_hours' => (int) env('ONBOARDING_PENDING_HOURS', 24),

    // ONB-08: assisted tenants not handed over after this many days are reported to staff.
    'handover_report_days' => (int) env('ONBOARDING_HANDOVER_REPORT_DAYS', 30),

    // ONB-07: short help per checklist item. Video URLs are data, empty
    // until the videos exist; the text lives in the translation files.
    'help_videos' => [
        'company' => null,
        'tax_id' => null,
        'item' => null,
        'till' => null,
        'first_sale' => null,
    ],
];
