<?php

return [
    'failed' => 'These credentials do not match our records.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    'password' => [
        'incorrect' => 'The provided password is incorrect.',
        'common' => 'This password is too common. Choose a less predictable one.',
    ],

    'unverified' => 'Verify your email or phone first. We’ve sent you a new code.',
    'deactivated' => 'This account is deactivated. Ask your administrator to reactivate it.',
    'locked' => 'Too many failed sign-ins. Try again in :minutes minutes.',

    'code' => [
        'invalid' => 'This code is not valid. Check it and try again.',
        'expired' => 'This code has expired. Ask for a new one.',
        'attempts' => 'Too many wrong codes. Ask for a new one.',
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
