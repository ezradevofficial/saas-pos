---
id: fuel-sell-fuel
title: Sell fuel at a pump
module: fuel
order: 20
permissions: [fuel.sale.create, fuel.voucher.redeem]
roles: [forecourt_attendant, shift_supervisor]
screens: [pos:NozzleSale]
---
Sell fuel by litres or by amount, from a nozzle on the open shift.

1. On "Forecourt", tap "Sell fuel".
2. Choose the nozzle. Your own nozzle is marked "Your nozzle". A nozzle without a price shows "No price": ask a manager to set it.
3. Choose "By litres" or "By amount" and type the "Litres" or the "Amount". The litres are rounded down to 3 decimals, so the charge can be a little below the amount typed.
4. For a fleet customer, tap "Fleet sale", choose the customer and the vehicle, and add the "Odometer (optional)". A blocked vehicle can still be sold to; the sale goes to the manager for review.
5. Tap "Charge <total>" and take the payment.

To sell shop items, tap "Sell shop items" on "Forecourt". You cannot sell fuel when no shift is open.
