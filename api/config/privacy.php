<?php

/**
 * NFR-08: data subject requests and consent (docs/privacy.md).
 */
return [

    // Days from receipt to the deadline, by the company's country. These
    // are a conservative internal target, NOT a statement of the law: the
    // owner's counsel must confirm the statutory periods under the Kenya
    // Data Protection Act 2019 and its regulations, and the DRC rules, and
    // set them here (docs/privacy.md, "To confirm").
    'deadline_days' => [
        'KE' => (int) env('PRIVACY_DEADLINE_DAYS_KE', 7),
        'CD' => (int) env('PRIVACY_DEADLINE_DAYS_CD', 7),
    ],
    'default_deadline_days' => (int) env('PRIVACY_DEADLINE_DAYS', 7),

    // Rows of each related table an access report includes (counts are always complete).
    'report_rows_per_table' => 500,

    'purposes' => ['marketing'],
    'channels' => ['email', 'sms', 'whatsapp', 'any'],
    'consent_sources' => ['back_office', 'storefront', 'import', 'lead_form'],
];
