<?php

// API-01..API-05: the public API (docs/adr/020-public-api.md).
return [
    'errors' => [
        'sandbox_limit_reached' => 'This developer sandbox already has its :max :what. Archive or reset its data to make room.',
        'sandbox_daily_limit' => 'This developer sandbox used its :max API requests for today. Try again tomorrow.',
        'route_refused' => 'API keys can’t be used here. Use this endpoint from the app, or see the developer portal for what keys can reach.',
        'scope_missing' => 'This API key doesn’t have the :scope scope. Add the scope to the key, or use another key.',
        'idempotency_key_invalid' => 'The Idempotency-Key header must be 1 to 255 letters, digits, dashes, underscores, dots or colons.',
        'idempotency_mismatch' => 'This Idempotency-Key was already used for a different request. Use a new key for a new request.',
        'idempotency_in_progress' => 'A request with this Idempotency-Key is still running. Wait for it to finish, then send it again.',
        'owner_role' => 'An API key can’t hold an Owner role. Choose another role.',
        'key_limit' => 'You already have :max active API keys. Revoke one you no longer use, then try again.',
        'key_revoked' => 'This API key is revoked. Create a new key.',
        'key_expired' => 'This API key has expired. Create a new key.',
        'not_sandbox' => 'Only a developer sandbox can be reset.',
        'sandbox_not_billed' => 'A developer sandbox has no subscription. Every module is already on.',
    ],
    'sandbox' => [
        'caps' => [
            'user' => 'users',
            'device' => 'devices',
            'outlet' => 'outlets',
            'records' => 'new records',
        ],
        'closing' => [
            'subject' => 'Your developer sandbox :name closes soon',
            'body' => 'Your developer sandbox :name has not been used for a while. It closes on :date.',
            'keep' => 'To keep it, sign in or send an API request with one of its keys before then.',
        ],
    ],
];
