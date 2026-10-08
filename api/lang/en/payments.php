<?php

// Payments at the till (concept note 7.1): API messages and list labels.
return [
    'errors' => [
        'push_unsupported' => 'This payment method can’t send a payment request to a phone. Confirm the payment with its reference instead.',
        'payout_unsupported' => 'This payment method can’t pay a refund back by itself. Refund the customer another way.',
        'check_unsupported' => 'This payment method can’t check payment codes. Match the payment in the back office.',
        'provider_refused' => 'The payment provider refused the request. Check the details and try again, or take another payment method.',
        'declined' => 'The customer declined the payment on their phone. Ask them to try again, or take another payment method.',
        'not_reached' => 'The customer’s phone didn’t answer in time. Ask them to check their phone and try again.',
        'provider_auth' => 'The payment provider refused the credentials. Check the payment method’s keys in the back office.',
        'phone_invalid' => 'Enter a valid mobile number, for example 0712 345 678.',
        'receipt_used' => 'This payment code was already used for another sale. Check the code on the customer’s message.',
        'receipt_invalid' => 'Enter the payment code from the customer’s message: 6 to 20 letters and digits.',
        'unreachable' => 'The payment provider couldn’t be reached. Try again, or take another payment method.',
        'amount_mismatch' => 'The amount the provider reports is not the amount of the payment. Check it in the back office.',
        'no_result' => 'The payment provider sent no result. Check the refund with the provider before paying it again.',
        'not_matchable' => 'This received payment can’t be matched to that payment. Choose an unverified or unanswered payment of the same method and currency.',
        'currency_not_supported' => 'This payment method takes only :currencies. Take the payment in that currency or with another method.',
        'whole_units' => 'This payment method takes whole :currency amounts only. Round the amount, or take the cents in cash.',
        'initiator_missing' => 'Refunds and payment checks need the initiator name and security credential. Add them to the payment method.',
        'phone_unknown' => 'The phone number of the original payment is not known, so the refund can’t be sent. Refund the customer another way.',
        'callback_unknown' => 'Unknown callback.',
        'callback_invalid' => 'The callback body is not valid.',
        'payout_failed' => 'The provider didn’t pay the refund. Refund the customer another way.',
        'code_not_found' => 'The provider doesn’t know this payment code. Check the payment with the customer.',
        'check_failed' => 'The provider couldn’t check this payment code. Check it in the back office.',
        'method_unavailable' => 'Choose an active payment method of this till’s company.',
        'register_unsupported' => 'Only M-Pesa methods register their URLs with the provider.',
        'not_configured' => 'Enter the payment method’s settings and credentials first.',
        'register_failed' => 'Safaricom refused to register the URLs. Check the shortcode and the app’s products on the Daraja portal, then try again.',
    ],

    // Sent to Safaricom: the text on the customer's phone (13 characters at most) and the refund remark.
    'daraja' => [
        'description' => 'Payment',
        'refund_remarks' => 'Refund',
    ],

    'purposes' => [
        'sale' => 'Sale',
        'refund' => 'Refund',
    ],

    'modes' => [
        'direct' => 'Direct',
        'stk' => 'Phone prompt',
        'manual' => 'Code entered',
        'payout' => 'Payout',
    ],

    'statuses' => [
        'pending' => 'Pending',
        'succeeded' => 'Paid',
        'failed' => 'Failed',
        'cancelled' => 'Declined',
        'timeout' => 'Timed out',
    ],

    'verifications' => [
        'unverified' => 'Not verified',
        'verified' => 'Verified',
        'mismatch' => 'Mismatch',
    ],

    'filters' => [
        'all' => 'All',
        'pending' => 'Pending',
        'succeeded' => 'Paid',
        'failed' => 'Failed',
        'cancelled' => 'Declined',
        'timeout' => 'Timed out',
        'unverified' => 'Not verified',
        'mismatch' => 'Mismatch',
    ],

    'receipt_statuses' => [
        'matched' => 'Matched',
        'unmatched' => 'Not matched',
    ],

    'receipt_filters' => [
        'all' => 'All',
        'matched' => 'Matched',
        'unmatched' => 'Not matched',
    ],

    'intents' => [
        'list_title' => 'Payments of :company',
        'columns' => [
            'created_at' => 'Created',
            'purpose' => 'Purpose',
            'mode' => 'How',
            'amount' => 'Amount',
            'phone' => 'Phone',
            'reference' => 'Reference',
            'receipt' => 'Payment code',
            'status' => 'Status',
            'verification' => 'Verification',
            'message' => 'Message',
        ],
    ],

    'receipts' => [
        'list_title' => 'Money received by :company',
        'columns' => [
            'transacted_at' => 'Received',
            'receipt' => 'Payment code',
            'amount' => 'Amount',
            'account_reference' => 'Account',
            'status' => 'Status',
        ],
    ],
];
