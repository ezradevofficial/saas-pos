<?php

// EXP-02: full tenant data exports.
return [
    'errors' => [
        'in_progress' => 'An export is already being prepared. Wait for it to finish, then try again.',
        'limit' => 'You can request :count exports in 24 hours. Try again later, or download a recent export.',
    ],

    'notifications' => [
        'ready' => [
            'label' => 'Data export ready',
            'subject' => 'Your data export is ready',
            'body' => "Hello {recipient_name},\n\nThe full export of your organisation's data is ready ({size}). Download it from Settings, Data export, before {expires_at}. After that it is deleted.\n\nOnly you can download it.",
        ],
    ],
];
