---
id: fuel-read-fuel-reports
title: Read the fuel reports
module: fuel
order: 65
permissions: [fuel.report.view]
roles: [station_manager, accountant]
screens: [/fuel/reports/:kind]
---
Six reports cover shifts, attendants, meters, tanks, products and the fleet.

1. Open "Fuel reports". Pick a tab: "Shift summary", "Attendant variance", "Nozzle meter history", "Tank losses and gains", "Sales by product" or "Fleet statement".
2. Choose a station if you want one only.
3. Set "From" and "To". Leave both empty for the last 30 days.
4. For "Tank losses and gains", choose a tank. For "Fleet statement", choose a customer.
5. Read the table. Totals per currency sit under the shift and fleet tables.

"Attendant variance" compares what each attendant collected with what was expected. "Tank losses and gains" shows every reconciliation of the period, and the net per tank.
