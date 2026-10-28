---
id: inventory-check-stock
title: Check stock levels
module: inventory
order: 20
permissions: [inventory.stock.view]
roles: [storekeeper, branch_manager]
screens: [/inventory/stock, /inventory/items/:itemId, /inventory/movements]
---
See what each location holds now and how it changed.

1. Open "Stock on hand" under "Inventory".
2. Type a code or name in "Search by item code or name". Narrow the list with "Company", "Branch", "Location" and "Category". Choose "Low only" under "Stock level" to see items at or below their reorder level.
3. Tap an item to open it. The tabs "By location", "Batches" and "Serials" show where it is and which batches or serial numbers are in stock.
4. Open the "Movements" tab, or "Movements" in the sidebar, to read every change of stock, newest first, and filter by "Movement", "From" and "To".
5. Open "Reorder levels" to see when {{app_name}} tells you that an item is low.

To record stock you already hold when you start, tap "Add opening stock", fill in the item, location, quantity and unit cost, then tap "Add opening stock".
