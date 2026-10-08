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

    // ADR 003: money amounts typed in major units (MoneyAmount rule).
    'money' => [
        'invalid' => 'Enter the :attribute as a number, for example 1250.50.',
        'too_many_decimals' => 'The :attribute can have at most :decimals decimals in :currency.',
        'min' => 'The :attribute must be at least :currency :min.',
        'max' => 'The :attribute must be at most :currency :max.',
    ],

    // CUR-01, CUR-02: currencies.
    'currency' => [
        'base_currency_locked' => 'This company’s base currency is locked because amounts have already been posted in it.',
        'too_many_reporting_currencies' => 'A company can have at most :max reporting currencies. Remove one before adding another.',
        'decimals_locked' => 'Amounts in this currency are already stored, so its decimals can’t change.',
        'in_use' => 'A company uses this currency as its base or reporting currency. Change the company first.',
        'base_is_reporting' => 'This currency is one of the company’s reporting currencies. Remove it from them first.',
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

    // CUR-03, CUR-06, CUR-07: exchange rates.
    'exchange_rate' => [
        'unavailable' => 'There is no exchange rate from :from to :to. Enter a shop rate first.',
        'invalid_rate' => 'Enter the :attribute as a number above zero with at most 10 digits before the point and 8 after, for example 2850.5.',
        'same_currency' => 'Choose two different currencies.',
        'buy_above_mid' => 'The buy rate can’t be above the mid rate.',
        'sell_below_mid' => 'The sell rate can’t be below the mid rate.',
        'duplicate' => 'A shop rate for this pair already starts at this time. Choose another time.',
        'tolerance_exceeded' => 'The :pair rate changed by :change% from the previous rate, more than the :tolerance% tolerance. It was saved; check it is right.',
        'attributes' => [
            'base' => 'base currency',
            'quote' => 'quote currency',
            'mid' => 'mid rate',
            'buy' => 'buy rate',
            'sell' => 'sell rate',
            'effective_at' => 'effective time',
        ],
    ],

    // MD-03, CP-01, CP-02: tax codes, rates, categories.
    'tax' => [
        'code_archived' => 'The tax code :code is archived. Restore it before using it or adding a rate.',
        'rate_missing' => 'The tax code :code has no confirmed rate on :date. Enter its rate before using it.',
        'rate_overlap' => 'A rate already starts on or after this date (latest start :date). Choose a date after :date.',
        'exempt_has_no_rate' => 'An exempt tax code has no rate.',
        'zero_rated_rate' => 'A zero-rated tax code has the rate 0.',
        'code_taken' => 'Another active tax code of this company uses this code. Choose another code.',
        'pack_missing' => 'No country pack is published for :country yet. Ask platform staff to publish it.',
        'category_other_company' => 'This category belongs to another company. Set default tax codes for its own company only.',
        'category_company_not_allowed' => 'You can’t set tax codes for this company.',
        'category_code_invalid' => 'Choose an active tax code of this company.',
        'category_company_required' => 'Tax categories are kept per company, like items. Choose the company this category belongs to.',
        'category_shared_mode' => 'Tax categories are shared across the group, like items, so a category can’t belong to one company. Remove the company.',
        'attributes' => [
            'code' => 'code',
            'name_en' => 'English name',
            'name_fr' => 'French name',
            'kind' => 'kind',
            'rate' => 'rate',
            'effective_from' => 'start date',
            'fiscal_code' => 'fiscal code',
            'category_name' => 'name',
            'company' => 'company',
            'codes' => 'default tax codes',
            'tax_code' => 'tax code',
        ],
    ],

    // MD-03: price lists.
    'price_list' => [
        'archived_default' => 'An archived price list can’t be the default. Restore it first.',
        'attributes' => [
            'name' => 'name',
            'currency' => 'currency',
            'tax_inclusive' => 'prices include tax',
            'is_default' => 'default price list',
        ],
    ],

    // TEN-08: shared or per-company master data.
    'master_data' => [
        'sharing_changed' => 'The sharing setting for this data changed while you were saving. Check the company and try again.',
        'records_need_company' => ':count record has no company. Choose the company that receives it before keeping this data per company.|:count records have no company. Choose the company that receives them before keeping this data per company.',
        'confirm_shared' => 'Sharing this data makes every company’s records visible across the group. Confirm to continue.',
        'attributes' => [
            'data_type' => 'data type',
            'mode' => 'sharing',
            'assign_to_company' => 'company that receives the records',
            'confirm' => 'confirmation',
        ],
    ],

    // MD-01, MD-06: parties.
    'party' => [
        'company_required' => 'This data is kept per company. Choose the company this record belongs to.',
        'company_not_allowed' => 'This data is shared across the group, so the record can’t belong to one company. Remove the company.',
        'company_not_reached' => 'You can’t move records to this company. Choose a company you work in.',
        'company_change_needs_confirmation' => 'This role change would move the record between shared and one company. Send the company, or none to share it, to confirm.',
        'price_list_other_company' => 'Choose an active price list of this record’s company.',
        'phone_invalid' => 'Enter the phone number with its country code, for example +254712345678.',
        'tag_invalid' => 'Tags use letters, numbers, spaces, hyphens and underscores, up to 40 characters.',
        'attributes' => [
            'company' => 'company',
            'kind' => 'kind',
            'name' => 'name',
            'legal_name' => 'legal name',
            'tax_id' => 'tax ID',
            'phones' => 'phone numbers',
            'phone' => 'phone number',
            'emails' => 'email addresses',
            'email' => 'email address',
            'addresses' => 'addresses',
            'address_line1' => 'address line 1',
            'currency' => 'currency',
            'payment_terms_days' => 'payment terms',
            'credit_limit' => 'credit limit',
            'credit_limit_currency' => 'credit limit currency',
            'price_list' => 'price list',
            'tags' => 'tags',
            'tag' => 'tag',
            'roles' => 'roles',
            'role' => 'role',
        ],
    ],
];
