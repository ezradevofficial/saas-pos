---
id: fa-register-asset
title: Register and activate an asset
module: fixed_assets
order: 2
permissions: [fixed_assets.asset.view, fixed_assets.asset.manage]
roles: [branch_manager, accountant, owner]
screens: [/fixed-assets/assets, /fixed-assets/assets/new, /fixed-assets/assets/:assetId, /fixed-assets/assets/:assetId/edit]
---
An asset is a draft until it is activated. Activating capitalises it and starts its depreciation.

1. Open "Asset register" under "Fixed assets" and tap "Register asset".
2. Under "Asset", type the "Name". Leave "Tag" empty to have it numbered. Choose the "Location" and the "Category", and type the "Serial number" and a "Description" if you have them.
3. Under "Acquisition", set the "Acquisition date" and the "In-service date" (depreciation starts there; it is the acquisition date by default). Check the "Currency", type the "Cost", and the "Supplier reference" and "Warranty until" if you have them.
4. Under "Depreciation", the category’s terms apply unless you choose others: change the "Depreciation method", the "Useful life (months)" or the "Residual value" for this asset only.
5. Tap "Save draft".
6. Open the draft. Tap "Activate", check the "In-service date" and confirm. The asset is capitalised at the acquisition date’s rate and starts depreciating from its in-service date.

While it is a draft, tap "Edit" to change it, or "Cancel draft" to drop it; the draft is kept, marked cancelled. On an active asset, tap "Print label" for its tag, and read:

- "Depreciation schedule": the charges posted and projected;
- "History": everything that happened to the asset;
- "Documents": tap "Upload a file" to attach an invoice or a warranty, "Download" to read it, "Archive" to remove it.

To find an asset, use "Search by tag, name or serial number" and the filters "Status", "Category", "Location" and the dates.
