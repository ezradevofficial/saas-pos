---
id: pos-review-held
title: Approve held voids, refunds and pay-outs
module: pos
order: 140
permissions: [pos.sale.void, pos.sale.refund, pos.cash.move]
roles: [branch_manager]
screens: [/pos/held]
---
A void, a refund or a cash pay-out waits here when the server could not prove who allowed it, for example after an offline approval at a till.

1. Open "Held for review" under "Point of sale".
2. Tap a record. Read the "Reason", the amount, who did it and "Manager named by the till".
3. Tap "Open the sale" to see the sale.
4. Tap "Approve" to accept it. Approving a void cancels the sale. Approving a refund refunds the lines and checks your refund limit. Approving a pay-out counts it in the drawer.
5. To refuse, tap "Reject", type the "Reason" so the till team knows, then tap "Reject". To decide later, tap "Keep waiting".

You can approve only the kinds you hold the permission for.
