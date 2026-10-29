---
id: stores-check-stock
title: Check and enter store stock
module: stores
order: 50
permissions: [stores.ledger.view, stores.stock.adjust]
roles: [storekeeper, branch_manager, owner, admin]
screens: [/stores/stock]
---
"Store stock" shows what each store holds, item by item.

1. Open "Store stock" under "Stores".
2. Search with "Search by item" and filter by "Store". Each row shows the item, the store, "On hand" and the "Last movement".
3. Tap "Export" and choose "Excel (.xlsx)", "CSV" or "PDF".

When Inventory is on, it keeps the stock of the stores: enter opening stock and adjustments there. The page says so.

When Inventory is off and you may adjust stock, "Enter stock" appears above the list:

1. Choose the "Store" and the "Entry": "Opening stock" or "Adjustment".
2. For an adjustment, choose the "Direction" ("Add to stock" or "Take out of stock") and the "Reason": "Counted", "Damaged", "Expired", "Found", "Correction" or "Other".
3. Search and choose the "Item", the "Unit", and type the "Quantity".
4. Tap "Enter stock". Stock never goes below zero. Taking stock out may also need someone allowed to approve stock decreases.
