---
id: inventory-set-up-categories-and-units
title: Set up item categories and units
module: inventory
order: 12
permissions: [core.item_category.view, core.item_category.create, core.item_category.edit, core.item_category.archive, core.uom.view, core.uom.edit]
roles: [storekeeper, admin, owner]
screens: [/catalogue/categories, /catalogue/units]
---
Categories group items in a tree. Units are the measures items are counted and sold in. Set both up before you add items.

Categories

1. Open "Categories" under "Catalogue".
2. Tap "Add category". Choose the "Company", type the "Name" and, for a subcategory, choose the "Parent". "None (top level)" makes a first-level category.
3. Tap "Add category" to save.
4. To add a branch under a category, tap "Add subcategory" on its row. Tap "Edit" to rename or move it.
5. To retire a category, tap "Archive", then "Archive category". It is no longer offered for new items; items in it keep it. A category with active subcategories cannot be archived: move or archive them first.
6. Tick "Show archived categories" to find an archived one, then tap "Restore".

Units

1. Open "Units" under "Catalogue". Search by "Code or name".
2. Tap "Add unit". Type the "Code" (up to 10 capital letters, digits or _, such as BOX12) and the "Name", and choose the "Kind": "Count", "Weight", "Volume", "Length" or "Time".
3. Tap "Add unit" to save.
4. Tap "Edit" to change a unit, "Archive" to retire it or "Restore" to bring it back. Every company shares units, so a change applies to all. A unit that active items use cannot be archived: change those items first.

Tap "History" on a row to see who changed it and when. To use a category or a unit on an item, see "Add an item with its units".
