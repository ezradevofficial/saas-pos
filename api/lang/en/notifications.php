<?php

// NOT-01..NOT-06: the notification service. Event texts use {placeholder}
// syntax (NOT-03); they are defaults, sent in each recipient's language,
// that a tenant can replace per channel with one text of its own.
return [
    'channels' => [
        'all' => 'All channels',
        'in_app' => 'In-app',
        'email' => 'Email',
        'push' => 'Push',
        'sms' => 'SMS',
        'whatsapp' => 'WhatsApp',
    ],

    'digests' => [
        'immediate' => 'Immediately',
        'daily' => 'Daily digest',
        'weekly' => 'Weekly digest',
    ],

    'statuses' => [
        'all' => 'All',
        'queued' => 'Queued',
        'sending' => 'Sending',
        'sent' => 'Sent',
        'delivered' => 'Delivered',
        'failed' => 'Failed',
        'skipped' => 'Skipped',
        'pending_digest' => 'Waiting for digest',
        'digested' => 'Sent in a digest',
    ],

    'inbox_statuses' => [
        'active' => 'Inbox',
        'unread' => 'Unread',
        'archived' => 'Archived',
        'all' => 'All',
    ],

    'reasons' => [
        'no_email' => 'The user has no verified email address.',
        'no_phone' => 'The user has no verified phone number.',
        'channel_unavailable' => 'This channel isn’t set up yet.',
        'user_deactivated' => 'The user is no longer active.',
        'secret_missing' => 'The link in this message can no longer be sent. Send a new one.',
    ],

    'delivery_errors' => [
        'send_failed' => 'The mail server or provider refused or didn’t answer.',
        'job_failed' => 'Sending stopped unexpectedly.',
    ],

    'template_sources' => [
        'default' => 'Default',
        'all' => 'Your text for all channels',
        'channel' => 'Your text for this channel',
    ],

    // Sample values for a template preview, besides each event's own.
    'samples' => [
        'recipient_name' => 'Amina Otieno',
    ],

    'placeholders' => [
        'recipient_name' => 'Recipient’s name',
        'app_name' => 'Application name',
    ],

    'events' => [
        'core' => [
            'notification' => [
                'test' => [
                    'label' => 'Test message',
                    'subject' => 'Test message from {sender_name}',
                    'body' => "Hello {recipient_name},\n\n{sender_name} sent you a test message:\n\n{message}",
                    'sms' => '{app_name}: test message from {sender_name}: {message}',
                ],
            ],
        ],
    ],

    'digest' => [
        'subject_daily' => 'Your daily summary: :count notification|Your daily summary: :count notifications',
        'subject_weekly' => 'Your weekly summary: :count notification|Your weekly summary: :count notifications',
        'intro' => 'Here is what happened since your last summary.',
    ],

    'mail' => [
        'open' => 'Open',
        'footer' => 'You get this email because of your notification settings in :app. To change them, open Settings, then Notifications.',
    ],

    'errors' => [
        'subject_line_break' => 'A subject is one line. Remove the line breaks.',
        'unknown_placeholders' => 'This text uses placeholders this notification doesn’t have: :placeholders. Use only: :allowed.',
        'unknown_event_type' => 'There is no notification type “:event”. Choose one from the list.',
        'channel_not_offered' => '“:event” isn’t sent by :channel. Choose another channel.',
        'mandatory_channel' => 'Your organisation requires :channel for “:event”. It can’t be switched off.',
        'mandatory_not_allowed' => '“:event” can’t be made mandatory.',
        'digest_not_allowed' => 'Email for “:event” is required immediately, so it can’t wait for a digest.',
    ],

    'attributes' => [
        'event_type' => 'notification type',
        'channel' => 'channel',
        'locale' => 'language',
        'subject' => 'subject',
        'body' => 'text',
        'channels' => 'channels',
        'digest' => 'email timing',
        'mandatory_channels' => 'mandatory channels',
    ],

    'inbox' => [
        'list_title' => 'Notifications',
        'columns' => [
            'subject' => 'Subject',
            'body' => 'Message',
            'type' => 'Type',
            'read' => 'Read',
            'created_at' => 'Received',
        ],
    ],

    'delivery' => [
        'list_title' => 'Notification deliveries',
        'columns' => [
            'created_at' => 'Created',
            'user' => 'Recipient',
            'recipient' => 'Sent to',
            'type' => 'Type',
            'channel' => 'Channel',
            'status' => 'Status',
            'reason' => 'Reason',
            'attempts' => 'Attempts',
            'error' => 'Last error',
            'sent_at' => 'Sent',
        ],
        'filters' => [
            'channel' => 'Channel',
        ],
    ],
];
