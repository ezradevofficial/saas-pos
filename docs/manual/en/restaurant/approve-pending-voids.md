---
id: restaurant-approve-pending-voids
title: Approve voids waiting for a manager
module: restaurant
order: 65
permissions: [pos.sale.void]
roles: [branch_manager]
screens: [/restaurant/pending-voids]
---
A waiter can ask to remove an item after it was sent to a station, from a till the server could not verify. The item stays on the bill until you decide.

1. Open "Pending voids". Each row shows the order, the item, its value, who asked, the reason and when.
2. To remove the item, tap "Apply". Confirm: the item leaves the bill and the station is told.
3. To keep it, tap "Reject" and confirm. The request is closed.

A row above your approval limit shows "Above your limit" and only the Reject button. Ask a manager with a higher limit to apply it.
