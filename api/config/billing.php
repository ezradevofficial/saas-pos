<?php

/*
 * The platform's own billing (SUB-02..SUB-07, ADR 016). Prices are data
 * (the catalogue, ADR 013); these are the defaults of the platform
 * settings staff can change (platform_settings) and the adapters that
 * collect the money. Owner input: docs/integrations.md.
 */

$development = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

return [
    // Platform settings (staff change them in the console; these are the defaults).
    'defaults' => [
        // SUB-05: days an unpaid invoice stays past due before read-only.
        'grace_days' => 7,
        // SUB-05: days the POS keeps uploading sales once read-only.
        'pos_window_days' => 3,
        // ONB-04: length of a new trial, in days.
        'trial_days' => 14,
    ],

    // SUB-05: reminders, in days before the due date (0: on the due date).
    'reminder_days' => [7, 3, 0],

    // SUB-07: switched-off module data is reported for purge after this many months (never deleted by the app).
    'purge_after_months' => 12,

    // SUB-02: pro-rated amounts are rounded half up to whole steps of minor
    // units: whole shillings in KES (M-Pesa takes whole shillings), cents in USD.
    'rounding_minor' => ['KES' => 100, 'USD' => 1],

    // SUB-03: the operator company's tax code its invoices carry, per
    // country (a code from the country pack; its rate is the company's
    // effective-dated rate, never a figure in code). Prices are
    // tax-inclusive. A code without a confirmed rate leaves the tax at 0
    // and the fiscal queue holds the invoice for attention.
    'tax_codes' => [
        'KE' => env('BILLING_TAX_CODE_KE', 'VAT_STD'),
        'CD' => env('BILLING_TAX_CODE_CD', 'VAT_STD'),
    ],

    // SUB-04: the adapter collecting each provider's payments for the
    // operator (App\Core\Payments\PaymentProviderRegistry). M-Pesa uses the
    // operator company's Daraja method unless overridden. The others have
    // no aggregator chosen yet: their adapters report themselves
    // unavailable until configured. `fake` (local and testing only)
    // confirms payments that never happened (NFR-06, EnvironmentGuard).
    'drivers' => array_filter([
        'mpesa_ke' => env('BILLING_DRIVER_MPESA_KE', $development ? 'fake' : null),
        'airtel_ke' => env('BILLING_DRIVER_AIRTEL_KE', $development ? 'fake' : 'airtel_money'),
        'airtel_money_cd' => env('BILLING_DRIVER_AIRTEL_CD', $development ? 'fake' : 'airtel_money'),
        'orange_money_cd' => env('BILLING_DRIVER_ORANGE_CD', $development ? 'fake' : 'orange_money'),
        'afrimoney_cd' => env('BILLING_DRIVER_AFRIMONEY_CD', $development ? 'fake' : 'afrimoney'),
        'card_aggregator' => env('BILLING_DRIVER_CARD', $development ? 'fake' : 'card_hosted'),
    ]),

    // Wallets a tenant may pay with, per billing country (provider keys of config/payment_providers.php).
    'wallets' => [
        'KE' => ['mpesa_ke', 'airtel_ke'],
        'CD' => ['airtel_money_cd', 'orange_money_cd', 'afrimoney_cd'],
    ],

    // Hosted card checkout (`card_hosted`): owner input once an aggregator is chosen.
    'card' => [
        'base_url' => env('BILLING_CARD_BASE_URL'),
        'merchant_id' => env('BILLING_CARD_MERCHANT_ID'),
        'api_key' => env('BILLING_CARD_API_KEY'),
    ],

    // Airtel Money, Orange Money and Afrimoney merchant APIs: owner input.
    'airtel_money' => ['base_url' => env('AIRTEL_MONEY_BASE_URL'), 'client_id' => env('AIRTEL_MONEY_CLIENT_ID'), 'client_secret' => env('AIRTEL_MONEY_CLIENT_SECRET')],
    'orange_money' => ['base_url' => env('ORANGE_MONEY_BASE_URL'), 'merchant_key' => env('ORANGE_MONEY_MERCHANT_KEY'), 'client_secret' => env('ORANGE_MONEY_CLIENT_SECRET')],
    'afrimoney' => ['base_url' => env('AFRIMONEY_BASE_URL'), 'merchant_id' => env('AFRIMONEY_MERCHANT_ID'), 'api_key' => env('AFRIMONEY_API_KEY')],
];
