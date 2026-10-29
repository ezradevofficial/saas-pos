---
id: mfg-issue-materials
title: Issue materials to a production order and take back what is unused
module: manufacturing
order: 4
permissions: [manufacturing.order.view, manufacturing.material.issue, manufacturing.material.return]
roles: [storekeeper, branch_manager, owner]
screens: [/manufacturing/orders, /manufacturing/orders/:orderId]
---
Materials leave stock for the order when you issue them, and return to stock at the same cost when you give them back.

1. Open "Production orders" under "Manufacturing" and open a released order. "Materials" shows for each one what is "Required", "Issued", "Returned", "Consumed" and "Still to issue".
2. Tap "Issue materials".
3. Type the quantity of each material, in its base unit. The dialog shows how much is still to issue. More than required is allowed and shows as a variance. Leave a line empty to issue nothing.
4. Add a "Note" if useful and tap "Issue materials". The issue is listed under "Issues".
5. To give back what was not used, tap "Return materials". Choose the "Issue", type the quantity of each material (up to what was issued less returned) and choose the "Reason": "Unused", "More than needed", "Wrong item" or "Other". Tap "Return materials".

Returns are listed under "Returns" and go back at the cost they went out at.
