---
id: inventory-count-stock
title: Count stock
module: inventory
order: 40
permissions: [inventory.count.view, inventory.count.create, inventory.count.count]
roles: [storekeeper, branch_manager]
screens: [/inventory/counts, /inventory/counts/new, /inventory/counts/:countId]
---
A stock count compares what is on the shelf with the stock on record. Starting a count freezes the counted items: adjustments and transfers of them wait until the count ends. Sales go on.

1. Open "Counts" under "Inventory" and tap "New count".
2. Choose the "Location" and the "Count type": "Full count" or "Cycle count".
3. For a cycle count, choose "Chosen items" or "A category". Add each item with "Add an item", or choose the "Category".
4. Tap "Start count".
5. Count the shelf. On the count, type each "Counted quantity of" the item. Tap "Save counts" as you go.
6. When every line is counted, tap "Submit count".

If there is no variance, the count is posted. If there is one, it goes for approval and the stock changes when it is approved. To abandon a count, tap "Cancel count" and give a reason; the items are unfrozen.
