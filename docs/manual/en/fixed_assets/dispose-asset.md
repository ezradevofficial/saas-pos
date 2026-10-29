---
id: fa-dispose-asset
title: Dispose of an asset
module: fixed_assets
order: 6
permissions: [fixed_assets.asset.view, fixed_assets.disposal.request, fixed_assets.disposal.approve]
roles: [branch_manager, owner]
screens: [/fixed-assets/disposals, /fixed-assets/assets/:assetId, /fixed-assets/disposals/:disposalId]
---
A disposal takes an asset out of use: a sale, a scrapping or a write-off. It is approved before it posts.

1. Open "Asset register" under "Fixed assets" and open an asset in use.
2. Tap "Dispose".
3. Choose the "Kind": "Sale", "Scrap" or "Write-off". Set the "Disposal date".
4. For a sale, type the "Proceeds" in the right currency.
5. Type the "Reason" and tap "Request disposal". The disposal opens on its own page, "Waiting for approval".
6. A branch manager decides it in the approvals inbox. See "Decide approvals".
7. Once it is approved, open the disposal and tap "Post disposal". The asset leaves the register as "Disposed".

The disposal page then shows "What it posted": the last depreciation, the proceeds in base currency and the "Gain or loss". Open "Disposals" under "Fixed assets" for the list. To stop a request that is waiting or approved, tap "Cancel disposal".
