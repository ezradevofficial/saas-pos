---
id: payment-methods-keys
title: Enter a payment provider's keys
module: core
order: 760
permissions: [core.payment_method.view, core.payment_method.edit, core.payment_method.configure]
roles: [owner, admin]
screens: [/settings/payment-methods]
---
A mobile money or card method needs the provider's keys before the till can take payments with it, for example the shortcode, consumer key and passkey for M-Pesa.

Warning: these keys let the provider move real money. Enter them only from the provider's own portal, never send them by email or chat, and never share them. Saved credentials are never shown again. Use the provider's sandbox until you have tested a payment end to end.

1. Open Finance, then "Payment methods".
2. Choose the company in the company switcher.
3. Tap "Settings" on the method.
4. Fill in each field the window asks for, such as "Shortcode", "Consumer key", "Consumer secret" and "Passkey". For M-Pesa, choose whether the shortcode is a "Paybill or Till".
5. To replace a saved credential, tap "Replace" and enter the new value. To clear one, tap "Remove".
6. Tap "Save settings". The window lists what is still needed.
7. When everything is saved, switch the method on at the till.

A method that is switched on can't have its settings removed. Switch it off first.
