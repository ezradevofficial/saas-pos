<?php

// Inventory module (docs/modules/inventory.md): API messages, list
// columns, document and notification wording. Business record names
// (items, locations) are shown as entered.
return [
    'errors' => [
        'item_unknown' => 'Choose an item that exists and is not archived.',
        'not_a_stock_item' => ':item is not a stock item. Only stock items keep stock.',
        'uom_not_on_item' => 'This unit is not one of the item\'s units. Choose one of its units.',
        'qty_invalid' => 'Enter a quantity other than zero.',
        'qty_precision' => 'This unit takes whole numbers (or up to 3 decimals for weights and measures). Change the quantity.',
        'insufficient_stock' => 'There is not enough stock of :item here, and this company does not allow negative stock. Lower the quantity or receive stock first.',
        'count_in_progress' => 'A stock count is running for this item here. Finish or cancel the count first.',
        'transfer_cross_company' => 'Both locations must belong to the same company. Transfers between companies are not available yet.',
        'same_location' => 'Choose two different locations.',
        'location_archived' => 'This location is archived. Choose an active location.',
        'location_unknown' => 'Choose a location that exists.',
        'serials_required' => 'Enter one serial number for each unit (:count).',
        'serial_unknown' => 'Serial number :serial is not known for this item.',
        'serial_not_in_stock' => 'Serial number :serial is not in stock here.',
        'serial_exists' => 'Serial number :serial is already in stock.',
        'batch_required' => 'Choose a batch for this item.',
        'batch_unknown' => 'Choose a batch of this item.',
        'expiry_required' => 'Enter the expiry date of this batch.',
        'tracking_locked' => 'This item already has stock movements, so its tracking can no longer change.',
        'costing_method_locked' => 'This company already has stock movements, so its costing method can no longer change.',
        'document_not_open' => 'This document can no longer be changed in this way.',
        'variant_self' => 'An item cannot be a variant of itself.',
        'variant_parent_is_variant' => 'The parent is itself a variant. Choose the main item.',
        'variant_has_variants' => 'This item has variants of its own, so it cannot become a variant.',
        'variant_attributes_taken' => 'Another variant of this item already has these attributes.',
        'receive_too_much' => 'You cannot receive more than was dispatched.',
        'serials_partial' => 'Items with serial numbers are received in full.',
        'count_incomplete' => 'Enter a counted quantity for every line before submitting.',
        'count_no_items' => 'There is nothing to count here. Choose items or a category.',
        'line_unknown' => 'This line is not part of the document.',
        'opening_used' => 'Stock of this item moved after the import, so it cannot be undone.',
        'price_list_currency' => 'Choose a price list.',
    ],
];
