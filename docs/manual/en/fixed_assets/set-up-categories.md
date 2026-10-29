---
id: fa-set-up-categories
title: Set up asset categories
module: fixed_assets
order: 1
permissions: [fixed_assets.asset.view, fixed_assets.category.manage]
roles: [accountant, admin, owner]
screens: [/fixed-assets/categories]
---
A category holds the default depreciation terms of a group of assets, such as vehicles or computers. Its accounts come from the posting rules of Accounting.

1. Open "Categories" under "Fixed assets" and tap "New category".
2. Type the "Code" and the "Name".
3. Choose the "Depreciation method": "Straight line", "Declining balance", "Units of production" or "No depreciation".
4. Type the "Useful life (months)". For declining balance, type the "Annual rate (%)" too. Neither is asked for units of production or no depreciation.
5. Type the "Residual value (%)", the share of the cost the asset is expected to keep at the end.
6. Choose the "Tax class" if you use the tax book, or "No tax class".
7. Tap "Save category".

Tap "Edit" on a row to change a category. Tap "Archive" to stop using one and "Restore" to bring it back.

To have purchases create assets, use "Items bought as assets": choose an "Item" and a "Category" and tap "Flag as asset". Goods received or invoiced for that item then become draft assets. Tap "Remove" to unflag an item.
