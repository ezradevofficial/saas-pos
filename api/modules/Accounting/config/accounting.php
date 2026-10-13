<?php

// Accounting module settings (docs/modules/accounting.md).
return [
    // ACC-07: event types the posting rules screen offers (the source contract's table);
    // a rule may name any other `module.event` type a module raises.
    'event_types' => [
        'pos.sale', 'pos.refund', 'pos.void', 'pos.shift_variance',
        'inventory.receipt', 'inventory.issue', 'inventory.adjustment',
        'procurement.supplier_invoice', 'payroll.run', 'loyalty.points',
    ],

    // ACC-13: the largest statement file accepted, in kilobytes.
    'statement_max_kb' => (int) env('ACCOUNTING_STATEMENT_MAX_KB', 2048),
];
