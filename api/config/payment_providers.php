<?php

/*
 * MD-04: payment providers a payment method can be linked to, and what each
 * needs before the method can be switched on. `settings` are plain values
 * (returned by the API); `secrets` are credentials, stored encrypted and
 * never returned. The provider adapters come with the POS (phase 4); the
 * keys of the providers other than Daraja are placeholders until then.
 *
 * `countries` lists where the provider is seeded for a new company, with
 * its English and French names (translation keys
 * `core.payment_method.defaults.{provider}`).
 */

return [
    'mpesa_ke' => [
        'type' => 'mobile_money',
        'countries' => ['KE'],
        // Safaricom Daraja (Lipa na M-Pesa).
        'settings' => ['shortcode'],
        'secrets' => ['consumer_key', 'consumer_secret', 'passkey'],
    ],
    'airtel_ke' => [
        'type' => 'mobile_money',
        'countries' => ['KE'],
        'settings' => ['merchant_id'],
        'secrets' => ['api_key'],
    ],
    'vodacom_mpesa_cd' => [
        'type' => 'mobile_money',
        'countries' => ['CD'],
        'settings' => ['merchant_id'],
        'secrets' => ['api_key'],
    ],
    'orange_money_cd' => [
        'type' => 'mobile_money',
        'countries' => ['CD'],
        'settings' => ['merchant_id'],
        'secrets' => ['api_key'],
    ],
    'airtel_money_cd' => [
        'type' => 'mobile_money',
        'countries' => ['CD'],
        'settings' => ['merchant_id'],
        'secrets' => ['api_key'],
    ],
    'afrimoney_cd' => [
        'type' => 'mobile_money',
        'countries' => ['CD'],
        'settings' => ['merchant_id'],
        'secrets' => ['api_key'],
    ],
    'card_aggregator' => [
        'type' => 'card',
        'countries' => ['KE', 'CD'],
        'settings' => ['merchant_id'],
        'secrets' => ['api_key'],
    ],
];
