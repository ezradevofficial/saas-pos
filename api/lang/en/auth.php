<?php

return [
    'failed' => 'These credentials do not match our records.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    'password' => [
        'incorrect' => 'The provided password is incorrect.',
        'common' => 'This password is too common. Choose a less predictable one.',
        'too_long' => 'This password is too long. Use at most :max characters, fewer if it has accented letters or symbols.',
    ],

    'unverified' => 'Verify your email or phone first. We’ve sent you a new code.',
    'unverified_wait' => 'Verify your email or phone first. Use the code we already sent you, or wait a little and sign in again to get a new one.',
    'deactivated' => 'This account is deactivated. Ask your administrator to reactivate it.',
    'locked' => 'Too many failed sign-ins. Try again in :minutes minutes.',

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
        'enabled' => 'Two-step verification is on.',
        'disabled' => 'Two-step verification is off.',
    ],

    'password_reset' => [
        'sent' => 'If an account uses this email or phone number, we’ve sent it a code to reset the password.',
        'done' => 'Your password has been changed. Sign in with your new password.',
    ],

    'notifications' => [
        'greeting' => 'Hello :name,',
        'verification_code' => [
            'subject' => 'Your :app verification code',
            'line' => 'Your verification code is :code.',
            'expiry' => 'It expires in :minutes minutes and works once.',
            'ignore' => 'If you didn’t ask for it, you can ignore this message.',
            'sms' => ':app code: :code. It expires in :minutes minutes.',
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
