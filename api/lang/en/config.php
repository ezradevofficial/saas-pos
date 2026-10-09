<?php

// LAY-06, LAY-07: versioned configuration (themes, layouts, templates).
return [
    'attributes' => [
        'key' => 'key',
        'name' => 'name',
        'scope_type' => 'applies to',
        'scope_id' => 'place, role or user',
        'payload' => 'configuration',
        'version' => 'version',
        'from' => 'copy from',
        'company' => 'company',
        'branch' => 'branch',
        'location' => 'location',
    ],

    'errors' => [
        'config_invalid' => 'This configuration has problems. Fix the items listed, then publish again.',
        'config_changed' => 'Someone changed this draft since you opened it. Reload to see their changes, then edit or publish again.',
        'config_draft_exists' => 'That place already has a draft. Replace it to copy, or open that draft first.',
        'config_busy' => 'Someone else changed this configuration at the same time. Try again.',
        'no_draft' => 'There is no draft to use. Make a change to start one.',
        'nothing_published' => 'Nothing is published yet, so there is nothing to copy. Publish it first, or copy its draft.',
        'version_not_found' => 'That version was never published for this configuration. Pick one from the history.',
        'version_is_live' => 'That version is already live. Pick an earlier version to roll back to.',
        'same_scope' => 'This configuration already applies there. Pick another company, branch or location.',
        'unknown_key' => 'This configuration has no such key. Use one the designer offers.',
        'payload_too_large' => 'This configuration is larger than :kb KB. Remove some items, then save again.',
        'place_out_of_scope' => 'You don’t work at this place. Pick a company, branch or location you are assigned to.',
    ],

    // Problems that keep a draft from being published (PayloadSchema).
    'problems' => [
        'root' => 'the configuration',
        'not_null' => ':path can’t be empty.',
        'type' => ':path must be of type :type.',
        'enum' => ':path must be one of: :values.',
        'required' => ':path is missing.',
        'unknown' => ':path isn’t a known setting. Remove it.',
        'min_items' => ':path needs at least :min items.',
        'max_items' => ':path can have at most :max items.',
        'duplicate' => ':path repeats “:value”. Each one may appear once.',
        'min_length' => ':path needs at least :min characters.',
        'max_length' => ':path can have at most :max characters.',
        'pattern' => ':path isn’t in the expected format.',
        'min' => ':path must be at least :min.',
        'max' => ':path must be at most :max.',
    ],
];
