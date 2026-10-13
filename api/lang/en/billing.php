<?php

// Plan catalogue and prices (SUB-01, ONB-03).
return [
    'catalogue' => [
        'unknown_country' => 'Choose a country we sell in: Kenya or the DR Congo.',
        'makeup_fixed' => 'This is fixed while the item has a price. Add a new module or bundle with its own price instead.',
        'unknown_module' => 'Choose a module from the catalogue.',
    ],

    'quote' => [
        'nothing_selected' => 'Choose at least one module or a bundle.',
        'unknown_module' => 'Choose modules from the catalogue. “:name” isn’t one.',
        'unknown_bundle' => 'Choose a bundle from the catalogue. “:name” isn’t one.',
        'unknown_cycle' => 'Choose monthly or annual billing.',
        'not_sold_here' => ':name isn’t sold in this country yet. Remove it or contact sales.',
        'bundle_outlet_limit' => ':name covers one outlet only. Choose a bundle that allows more outlets, or pick modules one by one.',
        'addon_requires_bundle' => ':name is sold only with a bundle. Choose a bundle that offers it.',
        'addon_not_available' => ':name isn’t offered with this bundle. Remove it or choose another bundle.',
        'amount_too_large' => 'These quantities are too large to price. Lower them or contact sales.',
        'requires_module' => ':name needs the module it adds to. Add that module too.',
    ],

    'coupon' => [
        'invalid' => 'This coupon code isn’t valid or has expired. Check the code or remove it.',
    ],

    'price' => [
        'exists' => 'A price from this date already exists. Choose another date.',
        'in_effect' => 'This price is already in effect and can’t be removed. Add a new price from a later date instead.',
    ],

    // SUB-02..SUB-07, LIM-05: subscriptions, the platform's invoices, payments and dunning.
    'errors' => [
        'unavailable' => 'Billing isn’t set up yet. Try again later or contact support.',
        'no_seller' => 'Billing isn’t available in :country yet. Contact support.',
        'no_subscription' => 'There is no subscription for your organisation yet. Choose a plan to start one.',
        'plan_quoted' => 'This bundle is priced by our sales team. Contact sales for a quote.',
        'coupon_already_applied' => 'Your subscription already has a coupon. Only one coupon can be used.',
        'coupon_not_applicable' => 'This coupon doesn’t apply to the plan you chose. Remove it or change the plan.',
        'no_change' => 'This is already your plan. Change a module, a quantity or the billing cycle first.',
        'quote_changed' => 'The price changed since you looked. Check the new amount, then confirm again.',
        'invoice_not_open' => 'This invoice is already paid or cancelled. Refresh to see its status.',
        'card_setup_unknown' => 'We couldn’t find this card setup. Add the card again.',
        'card_not_saved' => 'The card wasn’t saved by the card provider. Try again or use another method.',
        'payment_method_archived' => 'This payment method was removed. Choose another one.',
        'payment_method_unknown' => 'Choose one of your saved payment methods.',
        'provider_unavailable' => 'This payment method isn’t available yet. Choose another one or contact support.',
        'payment_failed' => 'The payment didn’t go through. Try again or use another method.',
        'already_subscribed' => 'Your subscription has already started. Pay its open invoice instead.',
        'not_in_trial' => 'This subscription is no longer in its trial.',
    ],

    'methods' => [
        'card_checkout' => 'Card',
    ],

    'read_only' => [
        'message' => 'Your subscription is overdue, so your account is read-only. Pay the open invoice in Settings, Subscription to continue.',
        'pos_stopped' => 'Your subscription is overdue and the till can no longer send sales. Ask the owner to pay the open invoice in Settings, Subscription.',
    ],

    'lines' => [
        'bundle' => 'Bundle :key',
        'module' => 'Module :key',
        'overage' => 'Extra use: :key',
        'annual_discount' => 'Annual billing discount',
        'coupon' => 'Coupon :key',
        'rounding' => 'Rounding',
        'proration' => 'Plan change for the rest of the period',
        'proration_days' => ':days of :period days',
        'months' => ':months months',
    ],

    'units' => [
        'outlet' => 'outlet',
        'device' => 'device',
        'warehouse' => 'warehouse',
        'online_store' => 'online store',
        'company' => 'company',
        'employee' => 'employee',
        'tenant' => 'organisation',
    ],

    'invoices' => [
        'list_title' => 'Subscription invoices',
        'number_type' => 'Subscription invoice',
        'columns' => [
            'number' => 'Number',
            'issued_at' => 'Issued',
            'kind' => 'Type',
            'period' => 'Period',
            'total' => 'Total',
            'due_at' => 'Due',
            'status' => 'Status',
        ],
        'kinds' => [
            'first' => 'First invoice',
            'renewal' => 'Renewal',
            'upgrade' => 'Plan change',
        ],
        'statuses' => [
            'open' => 'Open',
            'paid' => 'Paid',
            'void' => 'Cancelled',
        ],
        'filters' => [
            'all' => 'All',
            'open' => 'Open',
            'paid' => 'Paid',
            'void' => 'Cancelled',
        ],
    ],

    'notifications' => [
        'trial_ending' => [
            'label' => 'Trial ending',
            'subject' => 'Your trial ends in {days} days',
            'body' => "Hello {recipient_name},\n\nYour {app_name} trial ends on {date}. Your first invoice will be {amount}. Pay it in Settings, Subscription to keep everything running.",
            'sms' => '{app_name}: your trial ends on {date}. First invoice {amount}.',
        ],
        'renewal_due' => [
            'label' => 'Subscription renewal due',
            'subject' => 'Your subscription renews on {date}',
            'body' => "Hello {recipient_name},\n\nYour {app_name} subscription renews on {date} for {amount}. Pay it in Settings, Subscription, or keep a saved payment method to renew automatically.",
            'sms' => '{app_name}: your subscription renews on {date} for {amount}.',
        ],
        'past_due' => [
            'label' => 'Subscription invoice overdue',
            'subject' => 'Invoice {number} is overdue',
            'body' => "Hello {recipient_name},\n\nInvoice {number} for {amount} was due on {date} and is not paid. Pay it by {read_only_date} to keep full access; after that your account becomes read-only.",
            'sms' => '{app_name}: invoice {number} ({amount}) is overdue. Pay by {read_only_date} to keep full access.',
        ],
        'read_only' => [
            'label' => 'Account read-only',
            'subject' => 'Your account is read-only until invoice {number} is paid',
            'body' => "Hello {recipient_name},\n\nInvoice {number} for {amount} is still unpaid, so your account is read-only. Your tills keep sending sales until {pos_until}. Pay the invoice in Settings, Subscription to restore full access at once.",
            'sms' => '{app_name}: account read-only until invoice {number} ({amount}) is paid. Tills stop on {pos_until}.',
        ],
        'payment_received' => [
            'label' => 'Subscription payment received',
            'subject' => 'Payment received for invoice {number}',
            'body' => "Hello {recipient_name},\n\nWe received {amount} for invoice {number}. Thank you.",
            'sms' => '{app_name}: payment of {amount} received for invoice {number}.',
        ],
    ],
];
