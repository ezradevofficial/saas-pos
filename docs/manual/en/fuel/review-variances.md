---
id: fuel-review-variances
title: Approve variances and meter resets
module: fuel
order: 40
permissions: [fuel.variance.view, fuel.variance.approve, fuel.meter.approve_reset]
roles: [station_manager]
screens: [/fuel/settlements, /fuel/reconciliations, /fuel/meter-resets, /fuel/settlements/:settlementId, /fuel/reconciliations/:reconciliationId]
---
Decide what is outside tolerance. You cannot approve a shift you opened or closed, or your own request.

1. Open "Variance review". It compares what each attendant handed in with what the meters say. Open a settlement to read its figures and tenders.
2. Tap "Open the approvals inbox" to approve or reject it.
3. Open "Tank reconciliations" for the dip of each tank against the book stock. A difference outside tolerance waits for you.
4. Open "Meter resets". For a request from a supervisor, read the readings and reason, then tap "Approve" or "Reject".

The settlement may reopen if a sale reaches the server after the shift closed; the figures are then recalculated.
