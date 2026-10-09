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
        // LAY-03: form layouts.
        'unknown_tab' => ':path names the tab “:value”, which isn’t in this form. Pick one of its tabs.',
        'tab_required' => ':path: when a form has tabs, every section goes in one. Pick a tab for it.',
        'unknown_field' => ':path names the field “:value”, which this form doesn’t have. Remove it.',
        'required_hidden' => ':path: “:value” is required and has no default, so it can’t be hidden. Show it, or give the field a default.',
        'unknown_role' => ':path names a role that doesn’t exist or is archived. Pick another role.',
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
        'totals_required' => 'The totals block, with its tax lines, is required on this document in your country. Add it back from the palette.',
        'fiscal_twice' => 'The tax authority block can appear once. Remove the extra one.',
        'fiscal_not_allowed' => 'This document doesn’t carry tax authority data. Remove the tax authority block.',
        'tax_lines_locked' => 'Tax lines are always printed with the totals. Turn them back on.',
        'unknown_field' => ':path uses “:field”, which this document doesn’t have. Pick a field from the list.',
        'unknown_column' => ':path uses “:field”, which isn’t a column of this document. Pick a column from the list.',
        'row_nested' => ':path puts a row inside a row. Move its blocks out.',
        'row_on_thermal' => 'Two-column rows fit A4 and A5 paper only. Move the blocks out of the row, or pick A4 or A5.',
        // BR-02, BR-03: themes.
        'not_overridable' => ':path can’t be changed by a theme. A theme sets only the logos, the primary and accent colours, the sidebar, the corners and the font.',
        'asset_missing' => ':path names an image that isn’t one of your uploaded images of that kind. Upload it again.',
        // LAY-05: POS layouts.
        'pos_layout_button' => ':path is an item or category button with an action, or an action with an item or category. Give it one or the other.',
        'pos_layout_unknown_item' => ':path names an item that doesn’t exist or is archived. Remove it or choose another item.',
        'pos_layout_unknown_category' => ':path names a category that doesn’t exist or is archived. Remove it or choose another category.',
        'pos_layout_unknown_image' => ':path names an image that isn’t one of your item images or brand images. Choose another image.',
        'contrast' => 'In :mode mode, :pair has a contrast of :ratio:1 and needs at least :required:1. Choose a darker or lighter colour.',
    ],

    // BR-03: the words a contrast problem is made of.
    'theme' => [
        'modes' => ['light' => 'light', 'dark' => 'dark'],
        'pairs' => [
            'primary_text_page' => 'primary text on the page',
            'primary_text_card' => 'primary text on cards',
            'on_primary' => 'text on primary buttons',
            'primary_tint' => 'text on the primary tint',
            'on_accent' => 'text on the decisive button',
            'accent_fill' => 'the decisive button on cards',
            'sidebar_text' => 'sidebar text',
            'sidebar_active' => 'the selected sidebar item',
        ],
        // LAY-01, LAY-04: layout designers.
        'off_grid' => ':path goes past the :columns columns of the grid. Make it narrower or move it left.',
        'overlap' => ':path overlaps the widget “:value”. Move one of them.',
        'unknown_source' => ':path names the data source “:value”, which isn’t available. Pick another source.',
        'source_widget' => ':path: this data source can’t feed a “:value” widget. Pick another widget type or source.',
        'filter_value' => ':path isn’t a valid filter. Remove it, then save the view again.',
        'unknown_view' => ':path names the view “:value”, which isn’t in this list. Pick one of the saved views.',
    ],
];
