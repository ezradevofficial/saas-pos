<?php

// EXP-02: full tenant data exports.
return [
    'errors' => [
        'in_progress' => 'An export is already being prepared. Wait for it to finish, then try again.',
        'limit' => 'You can request :count exports in 24 hours. Try again later, or download a recent export.',
        'not_downloadable' => 'This export can’t be downloaded. Only the Owner who requested it can, until it expires.',
        'closing_unavailable' => 'This business account has no closing export ready to send. It is sent once the account is closed and its export is built.',
    ],

    'notifications' => [
        'ready' => [
            'label' => 'Data export ready',
            'subject' => 'Your data export is ready',
            'body' => "Hello {recipient_name},\n\nThe full export of your organisation's data is ready ({size}). Download it from Settings, Data export, before {expires_at}. After that it is deleted.\n\nOnly you can download it.",
        ],
    ],

    // NFR-08: a closed business account's export, by a signed link emailed to its Owners.
    'closing' => [
        'mail' => [
            'subject' => 'Download the data of :name',
            'body' => 'The business account :name is closed, so nobody can sign in to it any more. We have prepared a full export of its data (:size) for you.',
            'action' => 'Download the export',
            'expiry' => 'The link works until :date. After that it stops working and the export is deleted.',
            'private' => 'The link is yours alone and works without signing in. Do not forward this email.',
        ],
        'page' => [
            'invalid' => [
                'title' => 'This link is not valid',
                'body' => 'The download link is incomplete or was changed. Open it again from the email, or ask support to send it again.',
            ],
            'expired' => [
                'title' => 'This link has expired',
                'body' => 'The export of this closed business account is no longer available. Contact support if you still need the data.',
            ],
            'unavailable' => [
                'title' => 'This export is not available',
                'body' => 'There is no export to download with this link. Contact support if you need the data of a closed business account.',
            ],
        ],
    ],
];
