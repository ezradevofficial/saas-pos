<?php

// Plan catalogue and prices (SUB-01, ONB-03).
return [
    'catalogue' => [
        'unknown_country' => 'Choose a country we sell in: Kenya or the DR Congo.',
        'makeup_fixed' => 'This is fixed while the item has a price. Add a new module or bundle with its own price instead.',
        'unknown_module' => 'Choose a module from the catalogue.',
    ],

    'quote' => [
        'nothing_selected' => 'Choose at least one module or a bundle.',
        'unknown_module' => 'Choose modules from the catalogue. “:name” isn’t one.',
        'unknown_bundle' => 'Choose a bundle from the catalogue. “:name” isn’t one.',
        'unknown_cycle' => 'Choose monthly or annual billing.',
        'not_sold_here' => ':name isn’t sold in this country yet. Remove it or contact sales.',
        'bundle_outlet_limit' => ':name covers one outlet only. Choose a bundle that allows more outlets, or pick modules one by one.',
        'addon_requires_bundle' => ':name is sold only with a bundle. Choose a bundle that offers it.',
        'addon_not_available' => ':name isn’t offered with this bundle. Remove it or choose another bundle.',
        'amount_too_large' => 'These quantities are too large to price. Lower them or contact sales.',
        'requires_module' => ':name needs the module it adds to. Add that module too.',
    ],

    'coupon' => [
        'invalid' => 'This coupon code isn’t valid or has expired. Check the code or remove it.',
    ],

    'price' => [
        'exists' => 'A price from this date already exists. Choose another date.',
        'in_effect' => 'This price is already in effect and can’t be removed. Add a new price from a later date instead.',
    ],
];
