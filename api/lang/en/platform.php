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
    // RBAC-13: support access with consent.
    'support' => [
        'request_open' => 'You already have a request or an active session for this tenant. Use it, or end it first.',
        'request_limit' => 'You have asked this tenant for access :count times in the last hour. Wait an hour, or contact the tenant another way.',
        'not_pending' => 'This request was already decided.',
        'not_granted' => 'The tenant hasn’t granted this access, or it has ended. Ask again if you still need it.',
        'not_active' => 'This access isn’t active any more.',
        'entry_code_invalid' => 'This support session link has expired or was already used. Open the session again from the console.',
        'ended' => 'This support session has ended. Ask the tenant for access again if you still need it.',
        'audit_unavailable' => 'This change can’t be recorded in the audit log right now, so it was not made. Try again in a moment.',
        'route_refused' => 'A support session can’t use this page. The tenant’s own people manage it.',
        'read_only' => 'This support session is read-only, so nothing can be changed. Ask the tenant for write access if you need it.',
        'principal_name' => ':name (support)',
        'modes' => [
            'read' => 'read-only',
            'write' => 'read and write',
        ],
        'end_reasons' => [
            'revoked' => 'revoked by your team',
            'expired' => 'its time ran out',
            'ended_by_staff' => 'ended by our support team',
            'ended_in_session' => 'ended by our support team',
            'cancelled' => 'cancelled by our support team',
            'ended' => 'ended',
            'staff_changed' => 'the staff member’s account changed',
        ],
        'notifications' => [
            'requested' => [
                'label' => 'Support access requested',
                'subject' => '{staff_name} asks for {mode} access to your account',
                'body' => "Hello {recipient_name},\n\n{staff_name} from the {app_name} support team asks for {mode} access to your account for {hours} hours. Reason: {reason}\n\nApprove or deny it in Settings, Security, Support access. Nothing is opened until you approve.",
            ],
            'started' => [
                'label' => 'Support session started',
                'subject' => '{staff_name} started a support session',
                'body' => "Hello {recipient_name},\n\n{staff_name} from the {app_name} support team started a {mode} support session in your account. It ends at {until} at the latest. You can revoke it at any time in Settings, Security, Support access.",
            ],
            'ended' => [
                'label' => 'Support access ended',
                'subject' => 'Support access for {staff_name} has ended',
                'body' => "Hello {recipient_name},\n\nThe support access of {staff_name} has ended: {how}. Everything the session did is in Settings, Security, Support access.",
            ],
        ],
    ],
];
