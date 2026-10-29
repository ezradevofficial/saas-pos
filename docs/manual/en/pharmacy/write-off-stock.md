---
id: pharmacy-write-off-stock
title: Write off expired or damaged stock
module: pharmacy
order: 30
permissions: [pharmacy.writeoff.create, pharmacy.writeoff.view]
roles: [pharmacist, pharmacy_technician]
screens: [/pharmacy/write-offs, /pharmacy/write-offs/new, /pharmacy/write-offs/:writeOffId]
---
Take expired, damaged or recalled stock out of the books. A manager approves it first.

1. Open "Write-offs" and choose "New write-off".
2. Choose the "Location", the "Reason" and add a "Note" if needed.
3. Under "Lots to write off", choose each "Lot" and its "Quantity". Tap "Add a lot" for more.
4. Tap "Send for approval".

Stock leaves only when the request is approved. To withdraw the request first, open it and tap "Cancel write-off". Check "Expiry report" often so lots do not expire unseen.
