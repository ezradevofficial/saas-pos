<?php

/*
 * Payments at the till (concept note 7.1): provider adapters behind one
 * interface (App\Core\Payments\Contracts\PaymentProvider), payment intents
 * and provider callbacks. Credentials live on each company's payment method
 * (encrypted, MD-04), never here. What the owner must provide:
 * docs/integrations.md.
 */

$development = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

return [
    // Provider calls, callbacks' follow-up work and the timeout scan run on
    // their own Horizon supervisor (config/horizon.php).
    'queue' => env('PAYMENTS_QUEUE', 'payments'),

    // NFR-06: the fake driver confirms payments that never happened. It is
    // available only in local and testing; EnvironmentGuard refuses a real
    // environment that maps a provider to it.
    'allow_fake' => (bool) env('PAYMENTS_ALLOW_FAKE', $development),

    // Provider => adapter, overriding config/payment_providers.php (for
    // local work: PAYMENTS_DRIVER_MPESA_KE=fake). Empty in real
    // environments.
    'drivers' => array_filter([
        'mpesa_ke' => env('PAYMENTS_DRIVER_MPESA_KE'),
    ]),

    // The public base the provider calls back on (the API host as Safaricom
    // reaches it, HTTPS in every real environment). Callback paths are
    // `/api/v1/payments/callbacks/{token}/{kind}`; Safaricom refuses C2B
    // URLs containing words such as "mpesa", "safaricom", "sql" or "query",
    // so none of those appear in them.
    'callback_base_url' => env('PAYMENTS_CALLBACK_URL', env('APP_URL')),

    // An STK push the customer has not answered after this many seconds is
    // checked with the provider (STK query); one still open after
    // `stk_give_up_seconds` is marked `timeout`.
    'stk_timeout_seconds' => (int) env('PAYMENTS_STK_TIMEOUT', 90),
    'stk_give_up_seconds' => (int) env('PAYMENTS_STK_GIVE_UP', 300),

    // A refund payout (B2C) without a result after this many hours is
    // marked `timeout` for the back office to check.
    'payout_give_up_hours' => 24,

    // A manual M-Pesa payment (code typed by the cashier) not yet matched
    // to a C2B confirmation is checked with a transaction status query
    // after this many minutes (when the method has initiator credentials).
    'manual_verify_after_minutes' => (int) env('PAYMENTS_MANUAL_VERIFY_AFTER', 30),

    // C2B confirmations are matched to open intents of the same amount and
    // account reference created within this many minutes before.
    'c2b_match_window_minutes' => 30,

    'mpesa' => [
        // Sandbox by default. Production: https://api.safaricom.co.ke (set
        // MPESA_BASE_URL once Safaricom has taken the app live).
        'base_url' => env('MPESA_BASE_URL', 'https://sandbox.safaricom.co.ke'),

        // Daraja paths, configurable in case Safaricom versions them.
        'paths' => [
            'oauth' => env('MPESA_PATH_OAUTH', '/oauth/v1/generate?grant_type=client_credentials'),
            'stk_push' => env('MPESA_PATH_STK_PUSH', '/mpesa/stkpush/v1/processrequest'),
            'stk_query' => env('MPESA_PATH_STK_QUERY', '/mpesa/stkpushquery/v1/query'),
            'c2b_register' => env('MPESA_PATH_C2B_REGISTER', '/mpesa/c2b/v1/registerurl'),
            'b2c' => env('MPESA_PATH_B2C', '/mpesa/b2c/v1/paymentrequest'),
            'transaction_status' => env('MPESA_PATH_TRANSACTION_STATUS', '/mpesa/transactionstatus/v1/query'),
            'reversal' => env('MPESA_PATH_REVERSAL', '/mpesa/reversal/v1/request'),
        ],

        // Seconds per call; the OAuth token is cached until this many
        // seconds before it expires.
        'timeout' => 15,
        'token_margin' => 60,

        // B2C command for refunds (BusinessPayment, SalaryPayment or
        // PromotionPayment, as agreed with Safaricom for the shortcode).
        'b2c_command' => env('MPESA_B2C_COMMAND', 'BusinessPayment'),

        // Callbacks are accepted only from these addresses (Safaricom's
        // published callback ranges; confirm the current list with
        // Safaricom before go-live). The request address is the client as
        // the trusted proxy reports it (NodeBalancer, TrustProxies).
        'enforce_callback_ips' => (bool) env('MPESA_ENFORCE_CALLBACK_IPS', true),
        'callback_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('MPESA_CALLBACK_IPS',
            '196.201.214.200,196.201.214.206,196.201.213.114,196.201.214.207,196.201.214.208,196.201.213.44,196.201.212.127,196.201.212.138,196.201.212.129,196.201.212.136,196.201.212.74,196.201.212.69'
        ))))),
    ],
];
