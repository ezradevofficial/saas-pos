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
        // Online bookings one contact may make a day, and what one page may hold open and take in a day.
        'max_per_contact_per_day' => (int) env('SALON_BOOKING_MAX_PER_CONTACT_PER_DAY', 6),
        'max_open_per_page' => (int) env('SALON_BOOKING_MAX_OPEN_PER_PAGE', 300),
        'max_per_page_per_day' => (int) env('SALON_BOOKING_MAX_PER_PAGE_PER_DAY', 400),
        // Minutes a pending M-Pesa push counts as still waiting, so paying again does not send another.
        'stk_wait_minutes' => (int) env('SALON_BOOKING_STK_WAIT_MINUTES', 2),
    ],

    'notifications' => [
        // Messages one phone number gets a day (SMS and WhatsApp together).
        'per_phone_per_day' => (int) env('SALON_NOTIFICATIONS_PER_PHONE_PER_DAY', 20),
        // Messages one email address gets a day, and a branch's page may send in a day (confirmations and reminders).
        'per_email_per_day' => (int) env('SALON_NOTIFICATIONS_PER_EMAIL_PER_DAY', 10),
        'per_branch_per_day' => (int) env('SALON_NOTIFICATIONS_PER_BRANCH_PER_DAY', 1000),
    ],
];
