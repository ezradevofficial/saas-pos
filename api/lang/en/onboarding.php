<?php

// Self-onboarding (ONB-01..ONB-08).
return [
    'business_types' => [
        'retail' => ['name' => 'Retail shop', 'description' => 'Boutiques, hardware, electronics'],
        'supermarket' => ['name' => 'Supermarket or mini-mart', 'description' => 'Barcodes, scales, several tills'],
        'restaurant' => ['name' => 'Restaurant or café', 'description' => 'Tables, kitchen orders, split bills'],
        'bar' => ['name' => 'Bar or lounge', 'description' => 'Open tabs, fast rounds'],
        'hotel' => ['name' => 'Hotel or lodge', 'description' => 'Room charges, guest folios'],
        'pharmacy' => ['name' => 'Pharmacy or agrovet', 'description' => 'Batches, expiry dates'],
        'wholesale' => ['name' => 'Wholesale or distributor', 'description' => 'Price lists, credit, deliveries'],
        'van_sales' => ['name' => 'Route or van sales', 'description' => 'Sell and collect in the field'],
        'salon' => ['name' => 'Salon, spa or services', 'description' => 'Appointments, staff commissions'],
        'fuel_station' => ['name' => 'Fuel station', 'description' => 'Pumps, shifts, forecourt shop'],
        'non_pos' => ['name' => 'No till needed', 'description' => 'HR, payroll or accounting only'],
    ],

    'attributes' => [
        'business_type' => 'business type',
        'business_name' => 'business name',
        'outlets' => 'number of outlets',
        'modules' => 'modules',
        'cycle' => 'billing cycle',
        'bundle' => 'bundle',
        'step' => 'step',
        'status' => 'status',
        'contact_name' => 'contact name',
    ],

    'errors' => [
        'sample_loaded' => 'Sample data is already loaded. Remove it before loading it again.',
        'sample_not_loaded' => 'There is no sample data to remove.',
        'sample_in_use' => 'Sample data can’t be removed: :count of your own records use it. Change those records first, then try again.',
    ],

    'sample' => [
        'price_list' => 'Sample prices',
        'categories' => [
            'drinks' => 'Drinks (sample)',
            'groceries' => 'Groceries (sample)',
            'household' => 'Household (sample)',
        ],
        'items' => [
            'water' => 'Bottled water 500 ml',
            'soda' => 'Soda 300 ml',
            'juice' => 'Fruit juice 1 L',
            'tea' => 'Tea leaves 250 g',
            'rice' => 'Rice 1 kg',
            'sugar' => 'Sugar 1 kg',
            'flour' => 'Maize flour 2 kg',
            'oil' => 'Cooking oil 1 L',
            'soap' => 'Bar soap',
            'detergent' => 'Washing powder 500 g',
            'tissue' => 'Toilet tissue',
            'matches' => 'Matches',
        ],
        'customers' => [
            'customer_1' => 'Sample customer: Grace',
            'customer_2' => 'Sample customer: Joseph',
            'customer_3' => 'Sample customer: Neema',
        ],
    ],

    'assisted' => [
        'setup_user' => 'Setup by :name',
    ],
];
