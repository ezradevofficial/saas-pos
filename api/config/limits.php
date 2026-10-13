<?php

// LIM-01..LIM-05: usage metering and soft limits (docs/adr/018-usage-limits.md).
return [
    // LIM-03: days of warnings and daily reminders before adding more of an exceeded unit is refused.
    'window_days' => (int) env('LIMITS_WINDOW_DAYS', 7),

    // LIM-02: the web app's page where the Owner upgrades (the one-click "Upgrade" link).
    'upgrade_path' => '/settings/subscription',
];
