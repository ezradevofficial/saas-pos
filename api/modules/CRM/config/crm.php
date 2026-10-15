<?php

// CRM module settings (docs/modules/crm.md).
return [
    'forms' => [
        // CRM-06: public lead form submissions per minute, per IP and per form.
        'per_ip_per_minute' => (int) env('CRM_FORMS_PER_IP_PER_MINUTE', 10),
        'per_form_per_minute' => (int) env('CRM_FORMS_PER_FORM_PER_MINUTE', 60),
    ],
];
