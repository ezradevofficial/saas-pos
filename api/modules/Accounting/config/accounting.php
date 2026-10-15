<?php

// Accounting module settings (docs/modules/accounting.md).
return [
    // ACC-07, ACC-24: event types the posting rules screen offers (the source contract's table);
    // a rule may name any other `module.event` type a module raises.
    'event_types' => [
        'pos.sale', 'pos.refund', 'pos.void', 'pos.sale_summary',
        'inventory.cost_of_sales', 'inventory.receipt', 'inventory.opening', 'inventory.adjustment', 'inventory.count', 'inventory.transfer_loss', 'inventory.landed_cost', 'stores.consumption',
        'loyalty.gift_card_sold', 'loyalty.gift_card_redeemed', 'loyalty.gift_card_expired',
        'loyalty.points_earned', 'loyalty.points_redeemed', 'loyalty.points_expired', 'loyalty.points_adjusted',
        'loyalty.credit_charged', 'loyalty.credit_payment_received', 'loyalty.credit_adjusted',
        'procurement.supplier_invoice', 'procurement.landed_cost', 'procurement.landed_cost_bill', 'payroll.run',
        // ACC-34: Fixed assets (docs/modules/fixed-assets.md FA-22).
        'fixed_assets.capitalisation', 'fixed_assets.depreciation', 'fixed_assets.revaluation', 'fixed_assets.impairment', 'fixed_assets.disposal',
    ],

    // ACC-24: a source type without a rule of its own posts with another type's rule,
    // its sides swapped when the flag is true (a refund or void undoes a sale).
    'rule_fallbacks' => [
        'pos.refund' => ['pos.sale', true],
        'pos.void' => ['pos.sale', true],
        'pos.sale_summary' => ['pos.sale', false],
    ],

    // ACC-15: the base-currency rounding a manual journal with foreign lines may carry, in minor units.
    'manual_rounding_minor' => (int) env('ACCOUNTING_MANUAL_ROUNDING_MINOR', 2),

    // ACC-13: the largest statement file accepted, in kilobytes.
    'statement_max_kb' => (int) env('ACCOUNTING_STATEMENT_MAX_KB', 2048),
];
