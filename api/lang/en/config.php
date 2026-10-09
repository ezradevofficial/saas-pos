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
        // TPL-01..TPL-03: document templates.
        'unknown_block' => ':path isn’t a known block. Remove it.',
        'fiscal_required' => 'The tax authority block is required on this document in your country. Add it back from the palette.',
        'fiscal_twice' => 'The tax authority block can appear once. Remove the extra one.',
        'fiscal_not_allowed' => 'This document doesn’t carry tax authority data. Remove the tax authority block.',
        'tax_lines_locked' => 'Tax lines are always printed with the totals. Turn them back on.',
        'unknown_field' => ':path uses “:field”, which this document doesn’t have. Pick a field from the list.',
        'unknown_column' => ':path uses “:field”, which isn’t a column of this document. Pick a column from the list.',
        'row_nested' => ':path puts a row inside a row. Move its blocks out.',
        'row_on_thermal' => 'Two-column rows fit A4 and A5 paper only. Move the blocks out of the row, or pick A4 or A5.',
    ],
];
