---
id: inventory-set-stock-rules
title: Choose how stock is costed
module: inventory
order: 29
permissions: [inventory.stock.view, inventory.settings.edit]
roles: [branch_manager, admin, owner]
screens: [/inventory/settings]
---
Each company chooses how its stock is valued and whether stock may go below zero.

1. Open "Inventory settings" under "Inventory". If you see "Choose a company in the switcher to see its settings.", choose a company in the switcher.
2. Under "Costing method", choose "Weighted average" or "First in, first out (FIFO)". Costs are kept in the company’s base currency.
3. Turn "Allow negative stock" on if adjustments, transfers and counts may take stock below zero. Sales always go through, whatever this says.
4. Tap "Save settings".

Once the company has stock movements, you see "The costing method can no longer change". It is fixed, so choose it before you receive stock. If you see "You can see these settings but not change them.", ask an owner or administrator.
