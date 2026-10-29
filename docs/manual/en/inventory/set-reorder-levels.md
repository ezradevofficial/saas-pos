---
id: inventory-set-reorder-levels
title: Set reorder levels
module: inventory
order: 25
permissions: [inventory.reorder.view, inventory.reorder.edit]
roles: [storekeeper, branch_manager, admin, owner]
screens: [/inventory/reorder]
---
A reorder level is the quantity at which an item counts as low at a location. When stock falls to it, the people who manage that place are told.

1. Open "Reorder levels" under "Inventory".
2. Search with "Search by item code or name". Under "Stock level", choose "Low only" to see only items at or below their level. Under "Status", choose "Active rules", "Removed rules" or "All rules".
3. Read "On hand", "Reorder level", "Reorder quantity", "Low" and, for low items, "Low since".
4. Tap "Set reorder level".
5. Choose the "Item" and the "Location". Type the "Reorder level" and the "Reorder quantity", which is how much to order when stock is low.
6. Tap "Save reorder level".

To change a rule, tap its row, edit the numbers and tap "Save reorder level". To drop it, tap its row, clear "Reorder level" and tap "Remove reorder level"; it moves to "Removed rules" and can be set again later.

To order what is low, see "Create and send a purchase order".
