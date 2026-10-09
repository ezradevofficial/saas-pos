<?php

// LAY-01: dashboard data sources, as the designer lists them.
return [
    'sources' => [
        'approvals_waiting' => 'Approvals waiting for me',
        'approvals_mine' => 'My approvals',
        'workflows_overdue' => 'Overdue documents in workflows',
        'shortcuts' => 'Shortcuts',
        'pos_sales_today' => 'Sales today',
        'pos_sales_by_day' => 'Sales by day',
    ],
    // LAY-03: forms whose layouts tenants design, as the designer names their parts.
    'forms' => [
        'sections' => ['custom' => 'More details'],
        'item' => [
            'label' => 'Item',
            'sections' => ['details' => 'Details', 'units' => 'Units', 'barcodes' => 'Barcodes'],
            'fields' => [
                'company_id' => 'Company', 'code' => 'Code', 'type' => 'Type', 'name' => 'Name',
                'category_id' => 'Category', 'tax_category_id' => 'Tax category', 'units' => 'Units of measure', 'barcodes' => 'Barcodes',
            ],
        ],
        'party' => [
            'label' => 'Customer or supplier',
            'sections' => ['details' => 'Details', 'contact' => 'Contact', 'addresses' => 'Addresses', 'terms' => 'Terms'],
            'fields' => [
                'kind' => 'Kind', 'name' => 'Name', 'legal_name' => 'Legal name', 'tax_id' => 'Tax PIN', 'roles' => 'Roles',
                'company_id' => 'Company', 'phones' => 'Phones', 'emails' => 'Emails', 'addresses' => 'Addresses',
                'currency' => 'Currency', 'payment_terms_days' => 'Payment terms', 'credit_limit' => 'Credit limit',
                'price_list_id' => 'Price list', 'tags' => 'Tags',
            ],
        ],
        'custom_form' => ['lines' => 'Lines', 'attachments' => 'Attachments', 'details' => 'Details'],
    ],
];
