<?php

return [
    'defaults' => [
        'branch' => 'Main branch',
        'location' => 'Main outlet',
    ],

    'errors' => [
        'validation_failed' => 'Some fields need attention. Check them and try again.',
        'unauthenticated' => 'Sign in to continue.',
        'forbidden' => 'You don’t have permission to do this.',
        'not_found' => 'We couldn’t find what you asked for.',
        'method_not_allowed' => 'This action isn’t available here.',
        'too_many_requests' => 'Too many requests. Try again in :seconds seconds.',
        'http_error' => 'The request couldn’t be completed. Check it and try again.',
        'server_error' => 'Something went wrong on our side. Try again in a moment.',
    ],

    // TEN-02..TEN-06: companies, branches, locations.
    'organisation' => [
        'last_active' => 'Your organisation needs at least one active record here. Add another before archiving this one.',
        'has_active_children' => 'This record still has active records under it. Archive those first.',
        'parent_archived' => 'This record is archived. Restore it before adding to it.',
        'code_taken' => 'An active branch of this company already uses this code. Choose another code.',
    ],

    // TEN-05: POS devices.
    'devices' => [
        'not_pairable' => 'This device is already paired or suspended. Unpair it before pairing it again.',
        'not_suspended' => 'This device isn’t suspended, so there is nothing to resume.',
        'invalid_pairing_code' => 'This pairing code isn’t valid or has expired. Ask for a new code and try again.',
    ],
];
