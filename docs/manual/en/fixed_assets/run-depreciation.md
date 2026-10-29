---
id: fa-run-depreciation
title: Run monthly depreciation
module: fixed_assets
order: 4
permissions: [fixed_assets.depreciation.view, fixed_assets.depreciation.post]
roles: [accountant, owner]
screens: [/fixed-assets/runs, /fixed-assets/runs/:runId]
---
There is one run per month, posted in order. Each run posts to Accounting by branch and cost centre.

1. Open "Depreciation runs" under "Fixed assets".
2. Under "Run a month", choose the "Month" and tap "Preview". Nothing is saved yet.
3. Read the preview: "Assets", "Total", the "Convention" and the charge of each asset. "No charges this month." means nothing is due.
4. Tap "Post" followed by the month. The run is posted and appears under "Posted runs".

Runs post in order: if you choose another month, the page tells you the next one to post. A month inside a closed accounting period cannot be posted.

To undo a run, open it from "Posted runs", tap "Reverse run", type the reason and confirm. Accumulated depreciation goes back and the journals are reversed.
