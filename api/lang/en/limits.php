<?php

// LIM-01..LIM-04: usage limits.
return [
    'items' => [
        'outlet' => 'outlets',
        'device' => 'POS devices',
        'user' => 'users',
        'employee' => 'employees',
    ],

    'cost' => [
        'monthly' => ':amount a month',
        'unpriced' => 'the add-on rate',
    ],

    'errors' => [
        'blocked_ask_owner' => 'Your plan’s limit for :item is reached. Ask an Owner to upgrade the plan to add more.',
        'blocked' => 'Your plan includes :allowed :item and you have :count. Upgrade your plan to add more. Everything already set up keeps working.',
    ],

    'notifications' => [
        'exceeded' => [
            'label' => 'Usage above the plan',
            'subject' => 'You have more {item} than your plan includes',
            'body' => "Hello {recipient_name},\n\nYour organisation has {count} {item}. Your plan includes {allowed}. The {over} extra cost {extra_cost}, charged on your next invoice.\n\nUpgrade your plan by {blocks_on} to keep adding {item}. After that, adding more is paused until you upgrade. Everything already set up keeps working, and sales, payroll and approvals are never stopped.",
        ],
        'reminder' => [
            'label' => 'Usage above the plan: daily reminder',
            'subject' => '{days_left} days left to upgrade for your {item}',
            'body' => "Hello {recipient_name},\n\nYour organisation still has {count} {item}, and your plan includes {allowed}. The {over} extra cost {extra_cost}, charged on your next invoice.\n\nUpgrade your plan by {blocks_on} to keep adding {item}. Everything already set up keeps working.",
        ],
        'blocked' => [
            'label' => 'Usage above the plan: adding paused',
            'subject' => 'Adding more {item} is paused until you upgrade',
            'body' => "Hello {recipient_name},\n\nYour organisation has {count} {item}, and your plan includes {allowed}. You can't add more {item} until you upgrade your plan. Everything already set up keeps working, and sales, payroll and approvals are never stopped.\n\nThe {over} extra cost {extra_cost}, charged on your next invoice.",
        ],
    ],
];
