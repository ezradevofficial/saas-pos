---
id: payment-methods-order
title: Choose and order the payment methods at the till
module: core
order: 750
permissions: [core.payment_method.view, core.payment_method.edit]
roles: [owner, admin]
screens: [/settings/payment-methods]
---
Payment methods are how customers pay at the till, in the order the till shows them.

1. Open Finance, then "Payment methods".
2. Choose the company in the company switcher.
3. Use the switch on a method to turn it on or off at the till. A method marked "Setup needed" can't be switched on until its settings are complete (see "Enter a payment provider's keys"). A method can't be switched on if its currency is off; switch the currency on under Finance, then "Currencies" first.
4. Use the move up and move down buttons on a row to change the order the till shows. The page announces the new position.
