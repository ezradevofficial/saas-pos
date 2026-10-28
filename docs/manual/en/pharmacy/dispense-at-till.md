---
id: pharmacy-dispense-at-till
title: Dispense at the till
module: pharmacy
order: 10
permissions: [pos.sale.create, pharmacy.item.view]
roles: [pharmacist, pharmacy_technician]
screens: [pos:SellScreen]
---
Sell medicines on the till. The till picks the batch, asks for a prescription where the item needs one and asks for a pharmacist where the rules require it.

1. Add the items to the sale. Choose the customer who is buying if the till says "Choose the customer who is buying".
2. By default the batch with the earliest expiry is used. To pick another, tap "Choose batch", then "Use batch <batch>". A batch marked "Near expiry" still sells; an expired, recalled or on-hold batch is refused.
3. If "This sale needs a prescription.", tap "Add prescription". Fill "Patient name", "Prescriber name", "Prescriber licence number", "Facility" and "Date prescribed", then tap "Save prescription".
4. If "A pharmacist must approve this sale.", a pharmacist taps "Ask a pharmacist to approve". Without the right, a technician cannot approve alone.
5. Charge the sale as usual.

See "Sell items" for the rest of the sale.
