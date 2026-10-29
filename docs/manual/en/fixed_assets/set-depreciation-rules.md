---
id: fa-set-depreciation-rules
title: Set the depreciation convention and tax book
module: fixed_assets
order: 11
permissions: [fixed_assets.settings.edit, fixed_assets.tax.view]
roles: [admin, owner]
screens: [/fixed-assets/settings]
---
Two company settings shape depreciation.

1. Open "Settings" under "Fixed assets". If you have several companies, choose one in the company switcher.
2. Under "Depreciation", choose the "Convention": "By days in service", or "Half month in the first and last month". The choice saves at once. It is fixed once a run is posted: the page then says "Depreciation is already posted with this convention."
3. Under "Tax book", turn on "Use the tax book" to keep wear and tear allowances next to the book depreciation. The tax classes table lists each class with its method and "Annual rate".
4. A class marked "Needs confirmation" has no confirmed rate in the country pack yet. The page then says the tax book can’t be used yet; meanwhile give every category a tax class. "What the owner must confirm" lists what is missing.

Without the right to edit, the controls are read-only.
