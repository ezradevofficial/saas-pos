<?php

// SAL-14, SAL-15: limits of the salon module (docs/modules/salon.md). Environment overrides keep them out of the code.

return [
    'booking' => [
        // The public page: requests a minute per address (reading), and for writes (booking, paying a deposit) per address and per page.
        'per_ip_per_minute' => (int) env('SALON_BOOKING_PER_IP_PER_MINUTE', 60),
        'writes_per_ip_per_minute' => (int) env('SALON_BOOKING_WRITES_PER_IP_PER_MINUTE', 5),
        'writes_per_page_per_minute' => (int) env('SALON_BOOKING_WRITES_PER_PAGE_PER_MINUTE', 30),
        // Future online bookings one phone, email or customer may hold at a branch.
        'max_open_per_contact' => (int) env('SALON_BOOKING_MAX_OPEN_PER_CONTACT', 3),
    ],

    'notifications' => [
        // Messages one phone number gets a day (SMS and WhatsApp together).
        'per_phone_per_day' => (int) env('SALON_NOTIFICATIONS_PER_PHONE_PER_DAY', 20),
    ],
];
