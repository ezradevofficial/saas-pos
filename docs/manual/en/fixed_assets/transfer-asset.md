---
id: fa-transfer-asset
title: Transfer an asset to another place or person
module: fixed_assets
order: 5
permissions: [fixed_assets.asset.view, fixed_assets.transfer.request, fixed_assets.transfer.approve]
roles: [branch_manager, owner]
screens: [/fixed-assets/transfers, /fixed-assets/assets/:assetId, /fixed-assets/transfers/:transferId]
---
A transfer moves an asset to another location or custodian. It is approved before it applies.

1. Open "Asset register" under "Fixed assets" and open an asset in use.
2. Tap "Transfer".
3. Choose the "New location", or keep "Stays where it is". Choose the "New custodian", or keep "Same custodian".
4. Set the "Transfer date" and type the "Reason".
5. Tap "Request transfer". The transfer gets a number and opens on its own page, "Waiting for approval".
6. A branch manager decides it in the approvals inbox. See "Decide approvals". Once approved, the asset moves.

Open "Transfers" under "Fixed assets" to see every transfer with its asset, "From", "To" and date. To stop a request that is still waiting, open it and tap "Cancel transfer".
