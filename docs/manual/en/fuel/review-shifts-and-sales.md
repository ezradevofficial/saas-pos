---
id: fuel-review-shifts-and-sales
title: Review shifts and fuel sales
module: fuel
order: 36
permissions: [fuel.shift.view, fuel.sale.view]
roles: [station_manager, shift_supervisor]
screens: [/fuel/shifts, /fuel/shifts/:shiftId, /fuel/sales]
---
Read what each shift sold and which sales were flagged. A flag marks a sale that needs a look; no sale is refused.

1. Open "Shifts". Filter by station, status and the "From" and "To" dates.
2. Open a shift to read its "Summary" and the "Nozzles" table: opening and closing readings, pump tests, litres by meter, recorded litres and unrecorded litres.
3. Under "Attendants", tap "Open settlement" to read what that attendant handed in. See "Approve variances and meter resets" to decide it.
4. Open "Fuel sales" to read every sale with its litres, price, total, customer, vehicle and "Flags".
5. Tick "Flagged only" to list the sales that need a look.

Litres by meter are the closing reading minus the opening reading, minus pump tests, plus approved resets. Unrecorded litres are meter litres that no sale accounts for.
