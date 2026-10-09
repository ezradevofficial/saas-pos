<?php

// BR-02..BR-07: brand assets, custom domains, the email and SMS sender.
return [
    'attributes' => [
        'kind' => 'kind of image',
        'file' => 'image',
        'host' => 'domain',
        'slug' => 'subdomain',
        'email_from_name' => 'sender name',
        'email_from_address' => 'sender address',
        'sms_sender_id' => 'SMS sender ID',
    ],

    'errors' => [
        'asset_not_stored' => 'The image could not be saved. Try again in a moment.',
        'file_too_large' => 'This image is larger than :max. Choose a smaller one.',
        'file_type' => 'Use a JPEG, PNG or WebP image.',
        'file_unreadable' => 'This file can’t be read as an image. Choose another one.',
        'favicon_size' => 'A favicon must be a square image between 16 and 512 pixels a side.',
        'host_format' => 'Enter a full domain, such as erp.company.co.ke, without http:// or a path.',
        'platform_domain' => 'This is the platform’s own domain. Choose your subdomain on the Brand page instead.',
        'domain_added' => 'This domain is already on your list. Check it there, or remove it first.',
        'slug_taken' => 'This subdomain is already taken. Choose another one.',
        'slug_format' => 'Use lower-case letters, digits and hyphens, starting and ending with a letter or digit.',
        'slug_reserved' => 'This subdomain is reserved. Choose another one.',
        'sender_domain' => 'Send from an address on one of your verified domains. Add and verify the domain first.',
        'from_name_format' => 'The sender name must be one line without < > or quotes.',
        'sms_sender_format' => 'Use 3 to 11 letters, digits or spaces, with at least one letter.',
    ],

    'notifications' => [
        'domain_lost' => [
            'label' => 'Custom domain stopped working',
            'subject' => '{host} is no longer verified',
            'body' => "Hello {recipient_name},\n\nThe DNS record that proves {host} is yours was missing on three checks in a row, so {host} no longer opens your workspace, gets a certificate or sends your emails.\n\nCreate the TXT record again, then choose Check now on the Domains page.",
            'sms' => '{app_name}: {host} is no longer verified. Create its TXT record again and check it on the Domains page.',
        ],
    ],
];
