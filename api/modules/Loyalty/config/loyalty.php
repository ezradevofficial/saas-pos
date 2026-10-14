<?php

// Loyalty module settings (docs/modules/loyalty.md).
return [
    // LOY-20: the most gift cards or vouchers issued at once.
    'max_issue' => (int) env('LOYALTY_MAX_ISSUE', 500),
];
