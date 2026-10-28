---
id: pos-review-shifts
title: Review shifts and cash
module: pos
order: 130
permissions: [pos.shift.view]
roles: [branch_manager]
screens: [/pos/shifts, /pos/shifts/:shiftId]
---
Check each till’s cash after the cashier closes the shift.

1. Open "Shifts" under "Point of sale".
2. Use "Search tills or cashiers", or the filters "Status", "Opened from" and "Opened to".
3. Read "Variance" for each shift. It says "balanced", "Over by" or "Short by", per currency. A shift that is not counted shows "Not counted".
4. Open a shift to see "Cash per currency": "Opening float", "Expected", "Counted" and "Variance".
5. Under "Cash movements", check each pay-in and pay-out: the amount, the "Reason" and who approved it.

The expected cash is the opening float plus cash taken, minus change given and cash refunds, plus pay-ins and minus pay-outs. Records that reached the server after the shift closed are included, and a note on the shift says so.
