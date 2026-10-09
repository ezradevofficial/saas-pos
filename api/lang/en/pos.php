<?php

// POS module (docs/modules/pos.md): API messages.
return [
    'numbering' => [
        'receipt' => 'Receipt',
        'refund' => 'Refund receipt',
    ],

    'validation' => [
        'minor_units' => 'Send the amount in minor units as a whole number, for example "125000" for KES 1,250.00.',
        'quantity' => 'Send the quantity as a number above zero with up to 6 decimals, for example "1.5".',
    ],

    // POS-09: why an uploaded record was refused. The till shows the message;
    // records marked retryable are sent again later.
    'errors' => [
        'override_reference_required' => 'The manager approval must name the record it is for. Update the till and send it again.',
        'payload_mismatch' => 'A record with this id was already sent with other content. Give the new record a new id on the till.',
        'override_invalid' => 'The manager\'s approval could not be checked. Ask the manager to approve again with their PIN.',
        'override_expired' => 'The manager\'s approval has expired. Ask the manager to approve again.',
        'override_mismatch' => 'The manager approved something else. Ask the manager to approve this action.',
        'override_replayed' => 'This manager approval was already used for something else. Ask the manager to approve again.',
        'not_held' => 'This record is not waiting for review. Refresh the list.',
        'not_flagged' => 'This sale has no flags to review.',
        'upload_rejected' => 'Nothing in this upload was stored. Check each record\'s error, correct it and send it again.',
        'id_conflict' => 'This id is already used by another record. Give the record a new id on the till.',
        'user_unknown' => 'This user is not known in your organisation. Sign in again on the till and repeat the action.',
        'not_permitted' => 'This user is not allowed to do this here. Ask a manager to do it or to give the permission.',
        'override_required' => 'This needs a manager. Ask a manager to approve it with their PIN.',
        'limit_exceeded' => 'This is above your limit. Ask a manager to approve it with their PIN.',
        'override_not_permitted' => 'The approving manager is not allowed to do this here. Ask another manager.',
        'override_limit_exceeded' => 'This is above the approving manager\'s limit. Ask a manager with a higher limit.',
        'receipt_range_unknown' => 'This receipt number was not given to this till. Refresh the till\'s number ranges and number the receipt again.',
        'receipt_number_mismatch' => 'The receipt number does not match the till\'s number format. Refresh the till\'s number ranges.',
        'receipt_number_used' => 'This receipt number was already used. Refresh the till\'s number ranges.',
        'shift_unknown' => 'The shift is not on the server yet. Send the shift first, then this record again.',
        'shift_other_device' => 'The shift belongs to another till. Open a shift on this till.',
        'shift_closed' => 'The shift is closed. Open a new shift and repeat the action.',
        'shift_already_open' => 'This till already has an open shift. Close it before opening another.',
        'customer_unknown' => 'The customer is not one of this company\'s customers. Choose another customer.',
        'currency_unknown' => 'This currency is not used by your organisation. Ask an administrator to turn it on.',
        'price_list_unknown' => 'The price list is not one of this company\'s. Refresh the till\'s data.',
        'price_list_currency' => 'The price list is in another currency than the sale. Refresh the till\'s data.',
        'price_list_tax_mismatch' => 'The line says its price includes tax differently from its price list. Refresh the till\'s data.',
        'item_unknown' => 'The item is not sold by this company. Refresh the till\'s data.',
        'uom_unknown' => 'The unit is not one of the item\'s units. Refresh the till\'s data.',
        'discount_above_price' => 'The discount is larger than the line\'s price. Lower the discount.',
        'line_totals_inconsistent' => 'The line\'s amounts do not add up. Update the till and send the sale again.',
        'sale_totals_inconsistent' => 'The sale\'s totals are not the sum of its lines. Update the till and send the sale again.',
        'tax_code_missing' => ':item has no tax code for this company. Ask an administrator to set the item\'s tax category.',
        'rate_needed' => ':item can\'t be sold: its tax rate is marked "Rate needed". Ask an administrator to enter the rate.',
        'tax_code_archived' => ':item uses an archived tax code. Ask an administrator to update its tax category.',
        'tax_code_unknown' => 'The tax code is not one of this company\'s. Refresh the till\'s data.',
        'payment_method_unknown' => 'The payment method is not one of this company\'s. Refresh the till\'s data.',
        'payment_currency_mismatch' => 'This payment method takes another currency. Choose the method for this currency.',
        'payment_conversion_mismatch' => 'The payment\'s amount in the sale currency does not match its rate. Update the till and send the sale again.',
        'rate_missing' => 'A payment in another currency needs the exchange rate the till used. Refresh the till\'s rates.',
        'rate_pair_mismatch' => 'The exchange rate is for other currencies than the payment. Refresh the till\'s rates.',
        'rate_unavailable' => 'There is no exchange rate to the company\'s base currency for this sale yet. Enter the rate; the sale is sent again.',
        'sale_underpaid' => 'The payments do not cover the sale total. Take the rest of the payment.',
        'change_too_large' => 'The change is larger than what was overpaid. Correct the change.',
        'sale_unknown' => 'The sale is not on the server yet. Send the sale first, then this record again.',
        'sale_other_location' => 'The sale was made at another location. Void or refund it there.',
        'sale_already_voided' => 'The sale is already voided.',
        'sale_has_refunds' => 'The sale has refunds, so it can\'t be voided. Refund the remaining lines instead.',
        'sale_line_unknown' => 'The line is not part of this sale.',
        'refund_qty_exceeded' => 'This is more than was sold and not yet refunded. Lower the quantity.',
        'refund_total_mismatch' => 'The refund total does not match the lines given back. Update the till and send the refund again.',
        'refund_payments_mismatch' => 'The money returned does not add up to the refund total. Correct the payments.',
    ],

    'sale' => [
        'list_title' => 'Sales',
        'statuses' => [
            'completed' => 'Completed',
            'voided' => 'Voided',
        ],
        'columns' => [
            'receipt_number' => 'Receipt',
            'sold_at' => 'Sold',
            'status' => 'Status',
            'location' => 'Location',
            'cashier' => 'Cashier',
            'customer' => 'Customer',
            'total' => 'Total',
            'tax' => 'Tax',
            'base_total' => 'Total in base currency',
            'flags' => 'Flags',
        ],
        'filters' => [
            'from' => 'From',
            'to' => 'To',
        ],
    ],

    // TEN-07: the consolidated sales dashboard.
    'insights' => [
        'period_too_long' => 'Choose a period of at most :days days.',
    ],

    'shift' => [
        'list_title' => 'Shifts',
        'statuses' => [
            'open' => 'Open',
            'closed' => 'Closed',
        ],
        'columns' => [
            'opened_at' => 'Opened',
            'closed_at' => 'Closed',
            'status' => 'Status',
            'location' => 'Location',
            'device' => 'Till',
            'opened_by' => 'Opened by',
            'opening' => 'Opening float',
            'expected' => 'Expected cash',
            'counted' => 'Counted cash',
            'variance' => 'Variance',
        ],
    ],
];
