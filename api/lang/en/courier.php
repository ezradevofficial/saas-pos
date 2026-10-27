<?php

// MKT-04 (ADR 024): the courier's public API (E-commerce deliveries).
return [
    'errors' => [
        'delivery_not_assignable' => 'This delivery can’t be booked now: another rider or courier has it, it has finished, or its order is not ready. Refresh your list of orders.',
        'reference_reused' => 'This reference was already used with other details. Send a new reference for a new booking or update.',
        'courier_keys_only' => 'Only an API key or an app books and reports deliveries as a courier. Staff assign riders and confirm deliveries in E-commerce, Deliveries.',
        'courier_stopped' => 'This organisation has stopped bookings by this courier at this company. Ask them to restore it.',
        'delivery_unknown' => 'This delivery isn’t handed to you. Refresh your list of orders.',
        'delivery_status_invalid' => 'This delivery has moved on and can’t take this update. Get the order again to see its state.',
        'status_invalid' => 'This order has moved on and can’t take this update. Get the order again to see its state.',
        'code_locked' => 'Too many wrong delivery codes. Ask the store to confirm this delivery.',
        'code_invalid' => 'The delivery code is not right. Ask the customer for the code in their message and send it again.',
        'proof_required' => 'Send the customer’s 6-digit delivery code to mark the delivery as delivered.',
        'payload_mismatch' => 'This reference was already used with other details. Send a new reference for a new update.',
        'not_a_delivery' => 'This order is collected at the store, not delivered.',
        'rider_required' => 'Book the delivery before reporting it picked up.',
    ],
];
