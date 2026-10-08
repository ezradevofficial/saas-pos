<?php

// AUTO-01..AUTO-07: automation rules (App\Core\Automation).
return [
    // The queue rule runs, trigger scans and webhook deliveries go on
    // (its own Horizon supervisor, config/horizon.php).
    'queue' => env('AUTOMATION_QUEUE', 'automation'),

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

    // AUTO-03 webhooks: seconds before giving up (DNS lookups get
    // dns_timeout), bytes of the answer read at most and kept in the log,
    // and attempts per delivery with the wait (seconds) before the second
    // and third.
    'webhook_timeout' => 5,
    'dns_timeout' => 2,
    'webhook_max_download' => 65536,
    'webhook_response_bytes' => 1024,
    'webhook_attempts' => 3,
    'webhook_backoff' => [60, 300],

    // AUTO-06: one rule may run for one document at most this many times
    // in this many seconds (a cool-down against ping-pong edits).
    'document_runs' => 5,
    'document_window' => 600,

    // AUTO-06: a rule throttled more than this many times in an hour alerts
    // the automation administrators (once an hour).
    'throttle_alert_after' => 20,

    // AUTO-05: a run or webhook delivery left `running` / `sending` this
    // many minutes is reaped as failed (a worker died).
    'stuck_minutes' => 15,
];
