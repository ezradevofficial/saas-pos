---
id: ecommerce-refund-order
title: Refund an online order
module: ecommerce
order: 50
permissions: [ecommerce.order.view, ecommerce.order.refund]
roles: [owner, branch_manager]
screens: [/ecommerce/orders, /ecommerce/orders/:orderId]
---
Pay back a customer for a cancelled or returned order.

1. Open "Online orders" and tap an order whose payment shows "Refund due".
2. Tap "Record refund".
3. The money goes back the way the customer paid. If that cannot be done here, pay it back by hand and type its reference under "Refund reference".
4. Confirm. The payment status changes to "Refunded".

A refund above your limit, together with the order's other refunds, waits for a manager's approval in Approvals. Nothing moves until it is approved. See "Decide approvals".
