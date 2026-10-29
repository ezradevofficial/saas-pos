---
id: procurement-add-landed-costs
title: Add landed costs to goods received
module: procurement
order: 14
permissions: [procurement.landed_cost.view, procurement.landed_cost.create]
roles: [accountant, procurement_officer, owner]
screens: [/procurement/landed-costs, /procurement/landed-costs/new, /procurement/landed-costs/:landedCostId, /procurement/landed-costs/:landedCostId/edit]
---
Freight, duty, insurance and clearing are spread over the goods received, so each item’s cost includes them.

1. Open "Landed costs" under "Procurement" and tap "New landed cost".
2. Choose the "Branch", set the "Date" and choose the "Allocation method": "By value", "By quantity" or "By weight". Charges are converted to the base currency at the date’s rate. For "By weight", each received line needs its weight.
3. Type a "Description".
4. Under "Goods received it applies to", choose the goods received notes.
5. Under "Charges", type the "Charge" (for example freight), choose "Charged by", type the "Supplier bill number", check the "Currency" and type the "Amount". Tap "Add a charge" for more. The bill number is needed to post: the bill is posted to accounts payable.
6. Tap "Save landed cost". It is saved as a draft.
7. Open it. "Allocation" shows what posting will allocate to each received line, in base currency. If it says the cost cannot be split yet, fix what it points to.
8. Tap "Post landed cost".

Tap "Edit" to change a draft, or "Cancel draft" to drop it.
