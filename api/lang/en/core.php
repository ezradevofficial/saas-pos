<?php

return [
    'defaults' => [
        'branch' => 'Main branch',
        'location' => 'Main outlet',
    ],

    'errors' => [
        'validation_failed' => 'Some fields need attention. Check them and try again.',
        'unauthenticated' => 'Sign in to continue.',
        'forbidden' => 'You don’t have permission to do this.',
        'not_found' => 'We couldn’t find what you asked for.',
        'method_not_allowed' => 'This action isn’t available here.',
        'too_many_requests' => 'Too many requests. Try again in :seconds second.|Too many requests. Try again in :seconds seconds.',
        'http_error' => 'The request couldn’t be completed. Check it and try again.',
        'server_error' => 'Something went wrong on our side. Try again in a moment.',
    ],

    // TEN-02..TEN-06: companies, branches, locations.
    'organisation' => [
        'last_active' => 'Your organisation needs at least one active record here. Add another before archiving this one.',
        'has_active_children' => 'This record still has active records under it. Archive those first.',
        'parent_archived' => 'This record is archived. Restore it before adding to it.',
        'code_taken' => 'An active branch of this company already uses this code. Choose another code.',
    ],

    // TEN-05: POS devices.
    'devices' => [
        'not_pairable' => 'This device is already paired or suspended. Unpair it before pairing it again.',
        'not_suspended' => 'This device isn’t suspended, so there is nothing to resume.',
        'invalid_pairing_code' => 'This pairing code isn’t valid or has expired. Ask for a new code and try again.',
    ],

    // AUTH-02, AUTH-09, L10N-01: tenant settings.
    'settings' => [
        'attributes' => [
            'password_min_length' => 'minimum password length',
            'session_timeout_minutes' => 'session timeout',
            'default_locale' => 'default language',
        ],
    ],

    // CUR-01, CUR-02: currencies.
    'currency' => [
        'base_currency_locked' => 'This company’s base currency is locked because amounts have already been posted in it.',
        'too_many_reporting_currencies' => 'A company can have at most :max reporting currencies. Remove one before adding another.',
        'decimals_locked' => 'Amounts in this currency are already stored, so its decimals can’t change.',
        'in_use' => 'A company uses this currency as its base or reporting currency. Change the company first.',
        'not_active' => 'Activate this currency for your organisation first.',
        'not_in_catalogue' => 'Choose a current ISO 4217 currency.',
        'attributes' => [
            'code' => 'currency',
            'decimals' => 'decimals',
            'cash_rounding_minor' => 'cash rounding',
            'active' => 'active',
            'base_currency' => 'base currency',
            'reporting_currencies' => 'reporting currencies',
            'reporting_currency' => 'reporting currency',
        ],
    ],
];
