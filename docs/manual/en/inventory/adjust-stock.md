---
id: inventory-adjust-stock
title: Adjust stock and write off
module: inventory
order: 50
permissions: [inventory.adjustment.view, inventory.adjustment.create]
roles: [storekeeper, branch_manager]
screens: [/inventory/adjustments, /inventory/adjustments/new, /inventory/adjustments/:adjustmentId]
---
Use an adjustment to write stock down (damage, expiry, theft or loss) or up (found stock, a correction). It posts once it is approved.

1. Open "Adjustments" under "Inventory" and tap "New adjustment".
2. Choose the "Location" and the "Reason": "Damaged", "Expired", "Theft or loss", "Found", "Correction", "Internal use" or "Other". Add a "Note" to explain.
3. Under "Lines", choose the "Item". In "Change", choose "Decrease stock" or "Increase stock". Choose the "Unit" and type the "Quantity".
4. For stock you increase, type the "Unit cost", or leave it empty to use the item’s current cost.
5. Tap "Add line" for more items, then tap "Request adjustment".

The adjustment shows "Waiting for approval" until a manager decides it in "Approvals". If it cannot be posted because the stock changed, it shows "Not posted": check the stock and create a new one. To withdraw a request, open it and tap "Cancel adjustment".
