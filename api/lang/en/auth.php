<?php

return [
    'failed' => 'These credentials do not match our records.',
    'throttle' => 'Too many sign-in attempts. Try again in :seconds second.|Too many sign-in attempts. Try again in :seconds seconds.',

    'password' => [
        'incorrect' => 'The provided password is incorrect.',
        'common' => 'This password is too common. Choose a less predictable one.',
        'too_long' => 'This password is too long. Use at most :max characters, fewer if it has accented letters or symbols.',
    ],

    'unverified' => 'Verify your email or phone first. We’ve sent you a new code.',
    'unverified_wait' => 'Verify your email or phone first. Use the code we already sent you, or wait a little and sign in again to get a new one.',
    'deactivated' => 'This account is deactivated. Ask your administrator to reactivate it.',
    'locked' => 'Too many failed sign-ins. Try again in :minutes minute.|Too many failed sign-ins. Try again in :minutes minutes.',

    'code' => [
        'invalid' => 'This code is not valid. Check it and try again.',
        'expired' => 'This code has expired. Ask for a new one.',
        'attempts' => 'Too many wrong codes. Sign in again to get a new one.',
        'exhausted' => 'Too many wrong codes for this one. Sign in again to get a new code.',
    ],

    'two_factor' => [
        'enrollment_required' => 'Your role requires two-step verification. Set it up to continue.',
        'already_enabled' => 'Two-step verification is already on. Turn it off before setting it up again.',
        'not_started' => 'Start setting up two-step verification first, then enter the code.',
        'phone_required' => 'Add and verify a phone number before using codes by SMS.',
        'required_by_role' => 'Your role requires two-step verification, so it can’t be turned off. Ask your administrator if this is wrong.',
        'enabled' => 'Two-step verification is on.',
        'disabled' => 'Two-step verification is off.',
    ],

    'password_reset' => [
        'sent' => 'If an account uses this email or phone number, we’ve sent it a code to reset the password.',
        'done' => 'Your password has been changed. Sign in with your new password.',
    ],

    // AUTH-05: invitations.
    'invitation' => [
        'expired' => 'This invitation has expired. Ask your administrator to send a new one.',
        'revoked' => 'This invitation was withdrawn. Ask your administrator if you still need access.',
        'accepted' => 'This invitation has already been used. Sign in instead.',
        'stale' => 'This invitation no longer matches your organisation’s setup. Ask your administrator to send a new one.',
    ],

    // AUTH-13: user administration.
    'users' => [
        'contact_unverified' => 'This user never verified an email or phone number, so they can’t be reactivated. Invite them again instead.',
    ],

    'notifications' => [
        'greeting' => 'Hello :name,',
        'verification_code' => [
            'expiry' => 'It expires in :minutes minute and works once.|It expires in :minutes minutes and works once.',
            'ignore' => 'If you didn’t ask for it, you can ignore this message.',
            'verify_contact' => [
                'subject' => 'Your :app verification code',
                'line' => 'Your verification code is :code.',
                'sms' => ':app verification code: :code. It expires in :minutes minute.|:app verification code: :code. It expires in :minutes minutes.',
            ],
            'two_factor' => [
                'subject' => 'Your :app sign-in code',
                'line' => 'Your two-step verification code is :code.',
                'sms' => ':app sign-in code: :code. It expires in :minutes minute. Never share it.|:app sign-in code: :code. It expires in :minutes minutes. Never share it.',
            ],
            'password_reset' => [
                'subject' => 'Reset your :app password',
                'line' => 'Your password reset code is :code.',
                'sms' => ':app password reset code: :code. It expires in :minutes minute.|:app password reset code: :code. It expires in :minutes minutes.',
            ],
        ],
        'invitation' => [
            'subject' => 'Join :tenant on :app',
            'line' => ':inviter has invited you to join :tenant.',
            'action' => 'Accept the invitation',
            'expiry' => 'The invitation is valid until :date.',
            'sms' => ':app: you are invited to join :tenant. Accept here: :url',
        ],
        'new_device' => [
            'subject' => 'New sign-in to your :app account',
            'line' => 'Your account was signed in to from a new device on :time.',
            'details' => 'Device: :device. IP address: :ip.',
            'advice' => 'If this wasn’t you, change your password and end the session from your account settings.',
            'sms' => ':app: new sign-in to your account on :time. If this wasn’t you, change your password.',
        ],
    ],
];
