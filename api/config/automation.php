<?php

// AUTO-01..AUTO-07: automation rules (App\Core\Automation).
return [
    // The queue rule runs and trigger scans go on.
    'queue' => env('AUTOMATION_QUEUE', 'default'),

    // AUTO-05: attempts per run, and the wait (seconds) before the second
    // and third attempt.
    'attempts' => 3,
    'backoff' => [30, 120],

    // AUTO-06: how many rules may follow one another (a rule's actions
    // triggering the next rule) before the chain is stopped, and runs per
    // minute per tenant and per rule before further runs are throttled.
    'max_depth' => 3,
    'tenant_runs_per_minute' => 600,
    'rule_runs_per_minute' => 60,

    // AUTO-01 date triggers: documents are looked up once a day, at the
    // first scan after this local hour of each company.
    'date_scan_hour' => 6,

    // AUTO-03 webhooks: seconds before giving up, bytes of the response
    // body kept in the run log.
    'webhook_timeout' => 5,
    'webhook_response_bytes' => 1024,
];
