---
id: payment-methods-callbacks
title: Register M-Pesa callback URLs
module: core
order: 770
permissions: [core.payment_method.view, core.payment_method.edit, core.payment_method.configure]
roles: [owner, admin]
screens: [/settings/payment-methods]
---
Safaricom sends payment results to callback addresses. Each address holds a secret token.

Warning: share these addresses only through the Daraja portal. Getting new URLs replaces the old ones at once, so payments sent to the old addresses are lost until you register the new ones with Safaricom.

1. Open Finance, then "Payment methods".
2. Enter the shortcode and credentials first (see "Enter a payment provider's keys").
3. Tap "Callback URLs" on the M-Pesa method.
4. Copy each URL in "Callback URLs" and enter it in the Daraja portal.
5. Tap "Register C2B URLs". The page confirms when Safaricom accepted the confirmation and validation URLs.

If an address may have leaked, tap "Get new URLs", then "Replace the URLs", and register the new URLs with Safaricom straight away.
