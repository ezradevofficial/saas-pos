<?php

// Platform staff (ADR 013).
return [
    'user_exists' => 'A platform account with this email already exists.',

    'auth' => [
        'invalid_credentials' => 'This email and password don’t match a platform account. Check them and try again.',
        'deactivated' => 'This platform account is deactivated. Ask a platform owner to reactivate it.',
        'challenge_expired' => 'This sign-in has expired. Sign in again with your email and password.',
        'invalid_code' => 'This code isn’t valid. Enter the current 6-digit code from your authenticator app.',
        'setup_link_invalid' => 'This setup link has expired or was already used. Ask a platform owner for a new one.',
    ],
    // ADM-07: staff accounts.
    'staff' => [
        'cannot_deactivate_self' => 'You can’t deactivate your own account. Ask another platform owner.',
        'last_owner' => 'The platform needs at least one active owner. Make someone else an owner first.',
    ],
    // ADM-01: tenant management.
    'tenants' => [
        'already_suspended' => 'This tenant is already suspended.',
        'not_suspended' => 'This tenant isn’t suspended, so there is nothing to reactivate.',
        'suspended' => 'This account is suspended by the platform, so changes can’t be saved. The Owner can contact support to reactivate it.',
    ],
    // ADM-01c: messages to the tenant's Owners (platform account messages).
    'notifications' => [
        'tenant_suspended' => [
            'label' => 'Account suspended',
            'subject' => 'Your account is suspended',
            'body' => "Hello {recipient_name},\n\nOur team suspended your {app_name} account. Reason: {reason}\n\nYou can still sign in, see your data, export it and pay invoices. Contact support to reactivate the account.",
        ],
        'tenant_reactivated' => [
            'label' => 'Account reactivated',
            'subject' => 'Your account is active again',
            'body' => "Hello {recipient_name},\n\nOur team reactivated your {app_name} account. Reason: {reason}\n\nEverything works as before.",
        ],
        'trial_extended' => [
            'label' => 'Trial extended',
            'subject' => 'Your trial now ends on {date}',
            'body' => "Hello {recipient_name},\n\nOur team extended your {app_name} trial to {date}. Reason: {reason}",
        ],
    ],
];
