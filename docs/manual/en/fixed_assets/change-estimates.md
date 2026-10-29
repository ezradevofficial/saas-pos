---
id: fa-change-estimates
title: Change an asset’s depreciation estimates
module: fixed_assets
order: 3
permissions: [fixed_assets.asset.view, fixed_assets.asset.manage]
roles: [accountant, branch_manager, owner]
screens: [/fixed-assets/assets, /fixed-assets/assets/:assetId]
---
When an asset lasts longer or shorter than planned, change its estimates. What is already posted does not change.

1. Open "Asset register" under "Fixed assets" and open an asset in use.
2. Tap "Change estimates".
3. Choose the "Depreciation method" and type the "Useful life (months)" and the "Residual value". For declining balance, type the "Annual rate (%)".
4. Type the "Reason". It is required and kept in the asset’s history.
5. Tap "Save estimates". The new estimates apply from the next charge.
