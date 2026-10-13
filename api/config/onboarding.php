<?php

// Self-onboarding (ONB-01..ONB-08, docs/adr/017-onboarding.md).
return [
    // ONB-04: the free trial's length, from the day modules are picked.
    'trial_days' => (int) env('ONBOARDING_TRIAL_DAYS', 14),

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
