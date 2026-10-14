<?php

// Loyalty module (docs/modules/loyalty.md): API messages, list and export labels, notifications.
return [
    'numbering' => [
        'statement' => 'Customer statement',
    ],

    'errors' => [
        'credit_account_exists' => 'This customer already has an open credit account at this company.',
        'credit_account_closed' => 'This credit account is closed. Open it again before you record anything on it.',
        'credit_balance_not_zero' => 'The account still has a balance. Record a payment or an adjustment to bring it to zero, then close it.',
        'credit_limit_exceeded' => 'This charge takes the customer above their credit limit of :limit. Ask for a higher limit or record a smaller amount.',
        'gift_card_not_inactive' => 'This card was already sold or is blocked. Choose a card that has not been sold.',
        'gift_card_blocked' => 'This card is already blocked.',
        'gift_card_not_blocked' => 'This card is not blocked.',
        'coupon_archived' => 'This coupon is already archived.',
    ],

    'validation' => [
        'positive' => 'Enter an amount above zero.',
        'item_other_company' => 'Choose an item this company sells.',
        'gift_card_item_type' => 'Choose a service or non-stock item for gift cards.',
        'required_for_type' => 'Fill this in for a :type promotion.',
        'percent' => 'Enter a percentage above 0 and at most 100, with up to 4 decimals.',
        'items' => 'Choose items this company sells.',
        'categories' => 'Choose categories this company uses.',
        'branches' => 'Choose branches of this company.',
        'promotions' => 'Choose promotions of this company.',
        'codes_required' => 'Type at least one code or say how many to generate.',
        'codes_distinct' => 'Each code can be listed only once.',
        'codes_taken' => 'These codes are already used in this company: :codes. Choose other codes.',
        'customer_company' => 'Choose a customer of this company (or one shared across the group).',
        'statement_period' => 'A statement covers at most one year. Choose a shorter period.',
    ],

    'promotion' => [
        'list_title' => 'Promotions',
        'columns' => [
            'name' => 'Name',
            'type' => 'Type',
            'status' => 'Status',
            'priority' => 'Priority',
            'company' => 'Company',
            'version' => 'Version',
            'updated_at' => 'Last changed',
        ],
        'types' => [
            'percent' => 'Percentage off',
            'amount_off' => 'Amount off',
            'buy_x_get_y' => 'Buy X get Y',
            'bundle' => 'Bundle price',
        ],
        'statuses' => [
            'draft' => 'Draft',
            'active' => 'Active',
            'archived' => 'Archived',
        ],
    ],

    'gift_card' => [
        'list_title' => 'Gift cards and vouchers',
        'columns' => [
            'card' => 'Card',
            'kind' => 'Kind',
            'status' => 'Status',
            'initial' => 'Value',
            'balance' => 'Balance',
            'expires_on' => 'Expires',
            'issued_at' => 'Issued',
        ],
        'kinds' => [
            'gift_card' => 'Gift card',
            'voucher' => 'Voucher',
        ],
        'statuses' => [
            'inactive' => 'Not sold',
            'active' => 'Active',
            'blocked' => 'Blocked',
        ],
    ],

    'credit' => [
        'list_title' => 'Credit accounts',
        'columns' => [
            'customer' => 'Customer',
            'company' => 'Company',
            'balance' => 'Balance',
            'limit' => 'Credit limit',
            'status' => 'Status',
        ],
        'statuses' => [
            'open' => 'Open',
            'closed' => 'Closed',
        ],
        'kinds' => [
            'charge' => 'Sale on account',
            'payment' => 'Payment received',
            'reverse_charge' => 'Sale voided',
            'refund_credit' => 'Refund',
            'adjust' => 'Adjustment',
        ],
    ],

    'ageing' => [
        'title' => 'Debt ageing',
        'buckets' => [
            '0_30' => '0–30 days',
            '31_60' => '31–60 days',
            '61_90' => '61–90 days',
            '90_plus' => 'Over 90 days',
        ],
    ],

    // NOT-03: default texts, translated per recipient.
    'notifications' => [
        'points_expiring' => [
            'label' => 'Loyalty points expiring',
            'subject' => '{points} points of {company_name} expire by {date}',
            'body' => "Hello {recipient_name},\n\n{customers} customers of {company_name} have {points} points that expire by {date}. Remind them to use their points.",
            'sms' => '{app_name}: {points} loyalty points expire by {date}.',
        ],
        'credit_limit_near' => [
            'label' => 'Customer near their credit limit',
            'subject' => '{party_name} has used {percent}% of their credit limit',
            'body' => "Hello {recipient_name},\n\n{party_name} owes {balance}, {percent}% of their credit limit of {limit}. Ask for a payment before the next sale on account.",
            'sms' => '{app_name}: {party_name} has used {percent}% of their credit limit.',
        ],
        'credit_limit_exceeded' => [
            'label' => 'Customer over their credit limit',
            'subject' => '{party_name} is over their credit limit',
            'body' => "Hello {recipient_name},\n\n{party_name} owes {balance}, more than their credit limit of {limit}. Ask for a payment or review the limit.",
            'sms' => '{app_name}: {party_name} is over their credit limit.',
        ],
        'gift_card_low' => [
            'label' => 'Gift card balance low',
            'subject' => 'Gift card {card} has {balance} left',
            'body' => "Hello {recipient_name},\n\nGift card {card} has {balance} left after its last use.",
            'sms' => '{app_name}: gift card {card} has {balance} left.',
        ],
    ],

    'sales' => [
        'list_title' => 'Loyalty sales to review',
        'columns' => [
            'receipt' => 'Receipt',
            'sold_at' => 'Sold at',
            'customer' => 'Customer',
            'total' => 'Total',
            'flags' => 'To review',
        ],
    ],

    'flags' => [
        'tender_flagged' => 'Tender to check',
        'points_no_customer' => 'Points used without a customer',
        'points_invalid' => 'Points tender not readable',
        'points_overdrawn' => 'Points overdrawn',
        'points_value_mismatch' => 'Points value does not match',
        'points_currency' => 'Sale not in the programme currency',
        'gift_card_unknown' => 'Gift card not found',
        'gift_card_currency_mismatch' => 'Gift card in another currency',
        'gift_card_inactive' => 'Gift card not sold',
        'gift_card_blocked' => 'Gift card blocked',
        'gift_card_expired' => 'Gift card expired',
        'gift_card_refunded_after_use' => 'Gift card refunded after it was spent',
        'gift_card_overdrawn' => 'Gift card overdrawn',
        'gift_card_already_active' => 'Gift card sold twice',
        'gift_card_sale_mismatch' => 'Gift card sale does not match',
        'credit_no_customer' => 'Credit sale without a customer',
        'credit_no_account' => 'Credit sale without an account',
        'credit_currency_mismatch' => 'Credit sale in another currency',
        'credit_not_permitted' => 'Credit sold without the permission',
        'credit_limit_exceeded' => 'Over the credit limit',
        'credit_limit_overridden' => 'Credit limit overridden',
        'promotion_unknown' => 'Promotion not found',
        'promotion_not_applicable' => 'Promotion did not apply',
        'promotion_not_applied' => 'Promotion not given',
        'promotion_differs' => 'Promotion discount differs',
        'coupon_unknown' => 'Coupon not found',
        'coupon_overused' => 'Coupon used too often',
        'processing_failed' => 'Could not be processed',
        'voided_before_processing' => 'Voided before it was processed',
    ],
];
