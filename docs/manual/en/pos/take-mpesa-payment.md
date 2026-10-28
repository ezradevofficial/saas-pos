---
id: pos-take-mpesa-payment
title: Take an M-Pesa payment
module: pos
order: 60
permissions: [pos.sale.create]
roles: [cashier, branch_manager]
screens: [pos:PaymentScreen]
---
There are two ways to take M-Pesa. Send a payment request when the till is online. Type the code from the customer's message when it is not, or when the request fails.

## Send a payment request

1. On "Payment", tap the M-Pesa method under "Payment method".
2. Check "Customer's phone number". It is filled in from the customer when you added one.
3. Tap "Send payment request for" with the amount.
4. The till shows "Request sent to the customer's phone". The customer confirms with their M-Pesa PIN.
5. When "Payment received" appears, the payment is added to the sale on its own.
6. Tap "Complete sale".

If the customer does not confirm, the till says "No payment". Send the request again, or type the code.

## Type the code

1. Tap the M-Pesa method.
2. Type the code from the customer's confirmation message in "M-Pesa code". It has 8 to 12 letters and digits.
3. Tap "Add payment", then "Complete sale".

The till checks the code when it next syncs with the server. A code can pay one sale only. If the till says the code already paid another sale, check the customer's message again.

After a payment request is sent, the items and customer of the sale are locked. Complete the sale, or refund the payment first.
