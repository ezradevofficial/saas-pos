---
id: pharmacy-receive-stock
title: Receive stock by lot
module: pharmacy
order: 20
permissions: [pharmacy.lot.receive, pharmacy.lot.view]
roles: [pharmacist, pharmacy_technician]
screens: [/pharmacy/receive, /pharmacy/lots]
---
Record each delivery by batch and expiry, from the pack and the delivery note.

1. Open "Stock by lot" and choose "Receive stock".
2. Choose the "Location". Add the "Delivery note" if you have one.
3. For each lot, choose the "Item", then fill "Batch number", "Expiry date", "Quantity" and "Unit cost". "Manufacture date" is optional.
4. Tap "Add a lot" for another batch, or "Remove" to drop one.
5. Tap "Receive stock".

To move lots to another location of the same company, choose "Move stock". Lots leave the source first expiry first.
