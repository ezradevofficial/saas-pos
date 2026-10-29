---
id: pharmacy-move-stock
title: Move stock between locations
module: pharmacy
order: 25
permissions: [pharmacy.lot.transfer, pharmacy.lot.view]
roles: [pharmacist, pharmacy_technician]
screens: [/pharmacy/transfers/new]
---
Move lots between two locations of the same company. Lots leave the source with the nearest expiry first.

1. Open "Stock by lot" and tap "Move stock".
2. Choose the location in "From" and the location in "To".
3. Under "Items to move", choose each item and type its "Quantity". Add a line for each further item.
4. Read "Taken from" to see which lots will move. If only part is in stock, the page says how much is short.
5. Tap "Move stock". The message "Stock moved." confirms it.

An expired, quarantined or recalled lot cannot be moved.
