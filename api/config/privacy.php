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
    'consent_sources' => ['back_office', 'storefront', 'import', 'lead_form', 'opt_out_link', 'opt_out_reply'],

    // NFR-08: a reply by SMS or WhatsApp that is one of these words, alone
    // (any case, trailing punctuation ignored), withdraws marketing consent
    // on that channel (OptOuts). English and French.
    'opt_out_keywords' => ['STOP', 'STOP ALL', 'STOPALL', 'UNSUBSCRIBE', 'END', 'ARRET', 'ARRÊT', 'DESABONNER', 'DÉSABONNER'],

    // NFR-08: the versions of each consent wording a form may show, the
    // current one first. The text itself is in the app's catalogues under a
    // key naming the version (web: shop.consent.marketing_<version>). A
    // changed wording gets a new version here and a new key; earlier ones
    // stay listed while cached pages may still show them, so each record
    // names exactly the text the person saw.
    'wording_versions' => [
        'storefront_marketing' => ['2026_10'],
    ],
];
