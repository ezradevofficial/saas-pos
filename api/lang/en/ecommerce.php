<?php

// E-commerce and delivery module (docs/modules/ecommerce.md).
return [
    'errors' => [
        'forbidden' => 'You can’t do this here. Ask an administrator for access.',
        'product_unavailable' => 'This product is no longer sold online. Remove it from the cart.',
        'qty_invalid' => 'Enter a valid quantity for :product.',
        'price_missing' => ':product has no online price yet. Remove it from the cart, or ask the store to set a price.',
        'tax_rate_missing' => ':product can’t be sold until its tax rate is confirmed. Remove it from the cart.',
        'tax_code_missing' => ':product has no tax code yet. Remove it from the cart, or ask the store to set one.',
        'out_of_stock' => 'Not enough :product in stock. Lower the quantity or remove it.',
        'coupon_invalid' => 'This coupon code isn’t valid or has been used up. Check the code, or remove it.',
        'coupon_not_applicable' => 'This coupon doesn’t apply to the items in your cart.',
        'delivery_tax_rate_missing' => 'The delivery fee’s tax rate is not confirmed. Choose pickup, or ask the store.',
        'cart_empty' => 'Your cart is empty. Add a product first.',
        'below_minimum' => 'The minimum order is :amount. Add more to your cart.',
        'pickup_unavailable' => 'Pickup isn’t offered at that place. Choose another pickup point or delivery.',
        'delivery_unavailable' => 'This store doesn’t deliver. Choose pickup.',
        'zone_required' => 'Choose your delivery area.',
        'zone_unknown' => 'This delivery area isn’t available. Choose another.',
        'points_unavailable' => 'Points can’t be used for this order.',
        'points_insufficient' => 'You don’t have that many points. Use fewer points.',
        'points_below_minimum' => 'Use at least :points points, or none.',
        'points_exceed_total' => 'Those points are worth more than the order. Use fewer points.',
        'gift_card_unusable' => 'This gift card can’t be used. Check the code, its currency and its balance.',
        'gift_card_insufficient' => 'The gift card balance is too low. Use a smaller amount.',
        'total_changed' => 'The total has changed since you last saw it. Check the new total and place the order again.',
        'payment_unavailable' => 'Choose a payment method this store offers.',
        'address_required' => 'Enter the delivery address.',
        'phone_invalid' => 'Enter a valid phone number, for example 0712 345 678.',
        'rate_unavailable' => 'There is no exchange rate for :pair today. Ask the store to set one.',
        'idempotency_mismatch' => 'This order was already sent with other details. Reload the page and place it again.',
        'idempotency_key_required' => 'The request is missing its order key. Reload the page and try again.',
        'idempotency_key_invalid' => 'The order key is not valid. Use 16 to 100 letters, digits, dashes or underscores.',
        'customer_party_failed' => 'Your customer record could not be created. Try again, or contact the store.',
        'coupon_used_up' => 'This coupon has just been used up. Remove it, then place the order again.',
        'payment_in_progress' => 'The last payment request is still waiting for an answer. Check your phone, or wait a minute, then check the payment.',
        'payment_expired' => 'The time to pay this order has run out. Place the order again.',
        'payment_settled' => 'This order was paid meanwhile, so it was not cancelled.',
        'challenge_required' => 'Many codes were asked from this connection. Your browser solves a short check first, then asks again.',
        'payment_not_open' => 'This order has no payment to make now.',
        'payment_method_unavailable' => 'This payment method is not available now. Choose another, or contact the store.',
        'refund_reference_required' => 'Enter the reference of the refund you made.',
        'order_unpaid' => 'This order isn’t paid yet. Wait for the payment, or cancel it.',
        'not_a_delivery' => 'This order is for pickup. Mark it delivered when the customer collects it.',
        'rider_required' => 'Assign a rider before the order goes out.',
        'status_invalid' => 'This can’t be done while the order is :status.',
        'refund_above_limit' => 'This refund is above your limit. Ask a manager to do it.',
        'delivery_not_assignable' => 'This delivery can’t be assigned now: it is on its way, finished or its order is not ready.',
        'rider_unavailable' => 'Choose an active rider of this company.',
        'delivery_unknown' => 'This delivery isn’t assigned to you. Refresh your list.',
        'delivery_status_invalid' => 'This delivery has moved on. Refresh your list.',
        'code_locked' => 'Too many wrong delivery codes. Ask the store to confirm this delivery.',
        'code_invalid' => 'The code is not right. Check it and try again.',
        'proof_required' => 'Enter the customer’s delivery code or take a photo first.',
        'payload_mismatch' => 'This update was already sent with other details. Refresh your list.',
        'cashup_nothing_pending' => 'This rider has no cash to hand in for that currency.',
        'contact_invalid' => 'Enter a valid phone number or email address.',
        'customer_session_invalid' => 'You were signed out. Sign in again.',
        'customer_session_required' => 'Sign in first.',
        'webhook_unverified' => 'The request could not be verified.',
        'whatsapp_unavailable' => 'WhatsApp is not set up yet. Ask an administrator to connect a provider.',
        'branch_not_of_company' => 'Choose a branch of this company.',
        'location_not_of_branch' => 'Choose a location of this branch.',
        'location_not_of_company' => 'Choose locations of this company.',
        'handle_taken' => 'Another store already uses this web address. Choose another.',
        'price_list_invalid' => 'Choose a price list of this company in the store’s currency.',
        'tax_code_not_of_company' => 'Choose a tax code of this company.',
        'payment_method_invalid' => 'Choose a payment method of this company that can take :kind.',
        'store_not_ready' => 'Before going live, add a payment method and offer delivery or pickup (with a pickup point).',
        'item_not_sellable' => 'Choose active items of this company (kits can’t be sold online yet).',
        'slug_taken' => 'Another product of this store already uses this web address. Choose another.',
        'rider_user_invalid' => 'Choose an active user to be the rider.',
        'rider_exists' => 'This person is already a rider of this company.',
        'employee_invalid' => 'Choose an employee of this company.',
        'conversation_converted' => 'This chat already became an order.',
        'refund_not_due' => 'Nothing is waiting to be refunded on this order.',
        'loyalty_inactive' => 'Loyalty is not active.',
    ],

    'statuses' => [
        'placed' => 'Placed',
        'accepted' => 'Accepted',
        'picking' => 'Picking',
        'ready' => 'Ready',
        'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'returned' => 'Returned',
    ],

    'payment_statuses' => [
        'pending' => 'Payment pending',
        'paid' => 'Paid',
        'cod' => 'Cash on delivery',
        'cod_collected' => 'Cash collected',
        'failed' => 'Payment failed',
        'refund_due' => 'Refund due',
        'refunded' => 'Refunded',
    ],

    'channels' => [
        'web' => 'Online store',
        'phone' => 'Phone',
        'whatsapp' => 'WhatsApp',
    ],

    'fulfilments' => [
        'delivery' => 'Delivery',
        'pickup' => 'Pickup',
    ],

    'payment_kinds' => [
        'mpesa' => 'M-Pesa',
        'card' => 'Card',
        'cod' => 'Cash on delivery',
        'points' => 'Points',
        'gift_card' => 'Gift card',
        'loyalty' => 'Points and gift cards',
    ],

    'columns' => [
        'number' => 'Number',
        'store' => 'Store',
        'status' => 'Status',
        'payment_status' => 'Payment',
        'channel' => 'Channel',
        'fulfilment' => 'Fulfilment',
        'contact_name' => 'Customer',
        'contact_phone' => 'Phone',
        'total' => 'Total',
        'placed_at' => 'Placed',
    ],

    'lists' => [
        'orders' => 'Online orders',
    ],

    'numbering' => [
        'order' => 'Online orders',
    ],

    'orders' => [
        'payment_timeout' => 'Not paid in time',
    ],

    'deliveries' => [
        'failed_default' => 'Delivery failed',
    ],

    'fiscal' => [
        'delivery' => 'Delivery',
    ],

    // ECOM-08: sign-in codes (never stored, never through the notifier).
    'customers' => [
        'code' => [
            'subject' => 'Your sign-in code for :store',
            'line' => 'Your code is :code.',
            'expiry' => 'It works for :minutes minutes.',
            'ignore' => 'If you didn’t ask for it, you can ignore this message.',
            'sms' => ':store: your sign-in code is :code. It works for :minutes minutes.',
        ],
    ],

    // ECOM-20, NOT-02: messages to the customer.
    'notifications' => [
        'placed' => [
            'label' => 'Online order placed',
            'subject' => '{store_name}: order {order_number} received',
            'body' => "Hello {recipient_name},\n\nThank you for your order {order_number} of {total}. We will let you know when it moves.\n\nFollow it here: {tracking_url}",
            'sms' => '{store_name}: order {order_number} of {total} received. Follow it: {tracking_url}',
        ],
        'paid' => [
            'label' => 'Online order paid',
            'subject' => '{store_name}: payment received for {order_number}',
            'body' => "Hello {recipient_name},\n\nWe received your payment of {total} for order {order_number}.\n\nFollow it here: {tracking_url}",
            'sms' => '{store_name}: payment of {total} received for {order_number}.',
        ],
        'accepted' => [
            'label' => 'Online order accepted',
            'subject' => '{store_name}: order {order_number} accepted',
            'body' => "Hello {recipient_name},\n\nWe are preparing your order {order_number}.\n\nFollow it here: {tracking_url}",
            'sms' => '{store_name}: we are preparing order {order_number}.',
        ],
        'ready' => [
            'label' => 'Online order ready for pickup',
            'subject' => '{store_name}: order {order_number} is ready',
            'body' => "Hello {recipient_name},\n\nYour order {order_number} is ready for pickup.\n\nDetails: {tracking_url}",
            'sms' => '{store_name}: order {order_number} is ready for pickup.',
        ],
        'dispatched' => [
            'label' => 'Online order out for delivery',
            'subject' => '{store_name}: order {order_number} is on its way',
            'body' => "Hello {recipient_name},\n\nYour order {order_number} is on its way. Give the rider this delivery code: {delivery_code}\n\nFollow it here: {tracking_url}",
            'sms' => '{store_name}: order {order_number} is on its way. Delivery code: {delivery_code}',
        ],
        'delivered' => [
            'label' => 'Online order delivered',
            'subject' => '{store_name}: order {order_number} delivered',
            'body' => "Hello {recipient_name},\n\nYour order {order_number} was delivered. Thank you for shopping with us.",
            'sms' => '{store_name}: order {order_number} delivered. Thank you.',
        ],
        'cancelled' => [
            'label' => 'Online order cancelled',
            'subject' => '{store_name}: order {order_number} cancelled',
            'body' => "Hello {recipient_name},\n\nYour order {order_number} was cancelled. If you paid, the money goes back to you.\n\nDetails: {tracking_url}",
            'sms' => '{store_name}: order {order_number} was cancelled.',
        ],
    ],
];
