---
id: inventory-add-item
title: Add an item with its units
module: inventory
order: 10
permissions: [core.item.view, core.item.create, core.uom.view]
roles: [storekeeper, branch_manager]
screens: [/catalogue/items, /catalogue/items/new]
---
An item is what you stock and sell. Stock is counted in its base unit.

1. Open "Items" under "Catalogue", then tap "Add item".
2. Choose the "Company" and type the "Code" and the "Name". The code is unique and has no spaces.
3. Choose the "Type", for example "Stock item", and a "Category" if there is one.
4. Under "Units", choose the "Base unit". The units come from the "Units" list; if one is missing, ask an administrator to add it.
5. To sell or buy in boxes or packs, tap "Add unit", choose the "Unit" and type how many base units it contains in "Contains".
6. Under "Barcodes", tap "Add barcode", type the "Barcode" and choose its "Unit".
7. Tap "Create item".

To change an item later, open it from "Items", edit the fields and tap "Save changes". Archive an item with "Archive item"; its history is kept.
