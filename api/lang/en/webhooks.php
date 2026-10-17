<?php

// HOOK-01: webhook subscriptions and deliveries (docs/adr/021-webhooks.md).
return [
    'attributes' => [
        'url' => 'endpoint URL',
        'description' => 'description',
        'events' => 'events',
        'event' => 'event',
        'company_id' => 'company',
        'branch_id' => 'branch',
    ],

    'url' => [
        'invalid' => 'The endpoint URL isn’t a valid web address. Enter a full https:// address with a host name.',
        'not_https' => 'Webhook endpoints must use https://. Change the URL to a secure one.',
        'credentials' => 'The endpoint URL can’t contain a user name or password. Verify deliveries with the signing secret instead.',
        'private_address' => 'The endpoint URL points to a private or internal network, which isn’t allowed. Use a public address.',
        'unresolved' => 'The endpoint’s host name can’t be found. Check the URL.',
    ],

    'errors' => [
        'too_many' => 'You can have up to :count webhooks. Archive one you no longer use, then try again.',
        'event_not_visible' => 'You can’t see these records, so you can’t subscribe to this event. Ask an Owner to subscribe instead.',
        'principal_inactive' => 'The user this webhook sends as is no longer active. Create the webhook again as an active user.',
        'webhook_inactive' => 'This webhook is switched off. Switch it on, then replay the delivery.',
        'replay_not_visible' => 'This delivery can’t be replayed: its event type is no longer available or the webhook’s user can no longer see it.',
        'not_owner' => 'Only the user this webhook sends as can change its URL, events or filter. You can change its description or switch it on or off; the Owner can take it over.',
        'take_over_not_visible' => 'You can’t see the records of some of this webhook’s events at its scope, so you can’t take it over. Remove those events first.',
    ],

    'test_message' => 'This is a test event. Your endpoint received it and can check its signature.',

    'notifications' => [
        'disabled' => [
            'label' => 'Webhook switched off',
            'subject' => 'A webhook was switched off after repeated failures',
            'body' => "Hello {recipient_name},\n\nThe webhook to {url} was switched off after {failures} deliveries in a row failed. No more events are sent to it.\n\nCheck that the receiving system is running, then switch the webhook on again in Settings, Webhooks. Failed deliveries can be replayed from its delivery log.",
        ],
    ],
];
