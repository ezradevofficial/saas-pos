---
id: mfg-check-backflushes
title: Check recipe backflushes
module: manufacturing
order: 7
permissions: [manufacturing.report.view]
roles: [branch_manager, storekeeper, owner, admin]
screens: [/manufacturing/backflushes]
---
When the till sells a dish or a drink with a recipe, its ingredients are taken out of stock. This is called a backflush. Voids and refunds give them back.

1. Open "Recipe backflushes" under "Manufacturing".
2. Search with "Search by receipt". Filter by kind ("Consumed" or "Given back"), by location and by dates.
3. Read each row: the receipt, the menu items sold and the ingredients used. Open a row to see "Ingredients of" that receipt.
4. A line that was not taken from stock is listed under "Not backflushed" with the reason, for example "Stock item: moved by Inventory" or "Unit not on the item". Fix the item or the recipe, then adjust stock by hand if needed.

If no recipe has been sold yet, the page says so.
