---
id: ecommerce-cancel-order
title: Cancel an online order
module: ecommerce
order: 40
permissions: [ecommerce.order.view, ecommerce.order.cancel]
roles: [owner, branch_manager]
screens: [/ecommerce/orders, /ecommerce/orders/:orderId]
---
Cancel an order that has not left yet.

1. Open "Online orders" and tap the order. "Cancel order" is available until the order is handed to a rider.
2. Tap "Cancel order".
3. Type the "Reason". Switch on "Refund the delivery fee too" if the fee should go back as well.
4. Tap "Cancel the order".

If the customer already paid, the order shows "Refund due". Record the refund as described in "Refund an online order".
