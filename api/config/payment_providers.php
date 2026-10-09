<?php

/*
 * MD-04: payment providers a payment method can be linked to, and what each
 * needs before the method can be switched on. `settings` are plain values
 * (returned by the API); `secrets` are credentials, stored encrypted and
 * never returned. `optional_settings` and `optional_secrets` may be set but
 * are not needed to switch the method on (Daraja: refunds and checks of
 * manual codes need the initiator); `setting_values` limits a setting to a
 * list. `driver` is the adapter that talks to the provider
 * (App\Core\Payments\PaymentProviderRegistry): `mpesa_daraja` for
 * Safaricom; `manual` (the cashier confirms with the provider's reference)
 * for the providers whose aggregator is not chosen yet (concept note 7.1),
 * whose keys are placeholders until then.
 *
 * `countries` lists where the provider is seeded for a new company, with
 * its English and French names (translation keys
 * `core.payment_method.defaults.{provider}`).
 */

return [
    'mpesa_ke' => [
        'type' => 'mobile_money',
        'countries' => ['KE'],
        // Safaricom Daraja (Lipa na M-Pesa): STK push, C2B, B2C refunds.
        'driver' => 'mpesa_daraja',
        // `shortcode`: the Paybill number, or for a Till the store number
        // (head office) used as BusinessShortCode; `till_number` is then
        // the Till customers pay (PartyB). `b2c_shortcode`: the B2C
        // shortcode refunds are paid from; `initiator_name` and the
        // `security_credential` (generated on the Daraja portal from the
        // initiator password) are needed for refunds and status checks.
        'settings' => ['shortcode'],
        'optional_settings' => ['transaction_type', 'till_number', 'b2c_shortcode', 'initiator_name'],
        'setting_values' => ['transaction_type' => ['paybill', 'till']],
        'secrets' => ['consumer_key', 'consumer_secret', 'passkey'],
        'optional_secrets' => ['security_credential'],
    ],
    'airtel_ke' => [
        'type' => 'mobile_money',
        'countries' => ['KE'],
        'driver' => 'manual',
        'settings' => ['merchant_id'],
        'secrets' => ['api_key'],
    ],
    'vodacom_mpesa_cd' => [
        'type' => 'mobile_money',
        'countries' => ['CD'],
        'driver' => 'manual',
        'settings' => ['merchant_id'],
        'secrets' => ['api_key'],
    ],
    'orange_money_cd' => [
        'type' => 'mobile_money',
        'countries' => ['CD'],
        'driver' => 'manual',
        'settings' => ['merchant_id'],
        'secrets' => ['api_key'],
    ],
    'airtel_money_cd' => [
        'type' => 'mobile_money',
        'countries' => ['CD'],
        'driver' => 'manual',
        'settings' => ['merchant_id'],
        'secrets' => ['api_key'],
    ],
    'afrimoney_cd' => [
        'type' => 'mobile_money',
        'countries' => ['CD'],
        'driver' => 'manual',
        'settings' => ['merchant_id'],
        'secrets' => ['api_key'],
    ],
    'card_aggregator' => [
        'type' => 'card',
        'countries' => ['KE', 'CD'],
        'driver' => 'manual',
        'settings' => ['merchant_id'],
        'secrets' => ['api_key'],
    ],
];
