---
id: fa-revalue-asset
title: Revalue or impair an asset
module: fixed_assets
order: 7
permissions: [fixed_assets.asset.view, fixed_assets.revaluation.post]
roles: [accountant, owner]
screens: [/fixed-assets/revaluations, /fixed-assets/assets/:assetId]
---
A revaluation records a change of fair value. An impairment records a loss of value. Both post at once to the asset’s book and to Accounting.

1. Open "Asset register" under "Fixed assets" and open an asset in use.
2. Tap "Revalue or impair".
3. Choose the "Kind": "Revaluation" or "Impairment". Set the "Date".
4. Type the "New value": the fair value, or for an impairment the recoverable amount, in the base currency.
5. Type the "Reason" and tap "Post".

Open "Revaluations" under "Fixed assets" to see every one, with the "New value", the "Surplus change" and the "Profit or loss" it caused.
