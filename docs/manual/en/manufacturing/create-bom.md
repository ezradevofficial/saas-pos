---
id: mfg-create-bom
title: Create a bill of materials or a recipe
module: manufacturing
order: 1
permissions: [manufacturing.bom.view, manufacturing.bom.manage]
roles: [admin, owner]
screens: [/manufacturing/boms, /manufacturing/boms/new, /manufacturing/boms/:bomId, /manufacturing/bom-versions/:versionId]
---
A BOM (bill of materials) lists what a product is made of. A recipe does the same for a dish or a drink sold at the till.

1. Open "BOMs and recipes" under "Manufacturing" and tap "New BOM".
2. Choose the "Company" and the "Kind": "Production BOM" for a product made to stock in production orders (the item is a stock item), or "Recipe" for something made to order and used up when the till sells it (a kit or non-stock item).
3. Choose the "Item it makes" and type a "Name".
4. Under "First version", type "One batch makes" and choose the "Output unit". The quantities of the lines are for this much output. Type the "Labour minutes per batch" and a "Note" if you wish.
5. Tap "Add component" for each material. Choose the "Item", the "Unit" and the "Quantity". Add "Yield %" or "Wastage %" if some is lost. Tick "Use its recipe" to explode a component into its own BOM instead of taking it from stock.
6. Tap "Add by-product" for anything else the batch yields: choose the item and quantity, and the "Cost share %".
7. Tap "Create BOM". Its first version opens as a draft.
8. Check the lines. Under "Explosion", type a quantity in "For" to see what the version takes, down to the items taken from stock, and its cost at current stock costs.
9. Tap "Save draft", then "Publish". Choose "In force from", or leave it empty to take effect now, and confirm. A published version no longer changes.

An item has one BOM at a time. To search, use "Search by item or name" and filter by kind and by "Active" or "Archived".
