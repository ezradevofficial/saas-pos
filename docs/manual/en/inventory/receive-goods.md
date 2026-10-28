---
id: procurement-receive-goods
title: Receive goods from a purchase order
module: procurement
order: 10
permissions: [procurement.receipt.view, procurement.receipt.create, procurement.po.view]
roles: [storekeeper]
screens: [/procurement/receipts, /procurement/receipts/new]
---
Goods are received against an approved purchase order.

1. Open "Purchase orders" under "Procurement" and open the approved order.
2. Tap "Receive goods".
3. Check the "Receiving location". It is the order’s delivery location unless you choose another.
4. Type the supplier’s "Delivery note" number.
5. For each line, type what arrived in "Received" and, if some was refused, "Rejected" and "Reason for rejecting".
6. For items tracked by batch or serial number, type the "Batch number", the "Expiry date" or the "Serial numbers".
7. Tap "Save goods received". It is saved as a draft.
8. Check the draft, then tap "Post goods received". The stock goes up at the location.

If some lines are marked as not received into stock, check the item, its unit or its serial numbers, then adjust the stock by hand. To drop a draft, tap "Cancel draft".
