---
id: fuel-set-up-the-forecourt
title: Set up the forecourt
module: fuel
order: 60
permissions: [fuel.setup.view, fuel.setup.manage, fuel.settings.view, fuel.settings.edit]
roles: [owner, station_manager]
screens: [/fuel/setup, /fuel/tanks/:tankId]
---
Describe your stations, fuel products, tanks, pumps and nozzles once, and set the tolerances. Only the Owner can change the tolerances.

1. Open "Forecourt set-up". It has the tabs "Stations", "Fuel products", "Tanks", "Pumps and nozzles" and "Settings".
2. In "Stations", tap "New station", pick the location that is a forecourt and the currency its prices use, then tap "Create station". Change the currency before the first price or shift only.
3. In "Fuel products", tap "New fuel product", pick a stock item whose base unit is the litre, choose the "Fuel type" and, if you like, the "Grade". Tap "Create fuel product".
4. In "Tanks", tap "New tank", choose the station and the product, type the "Code" and the "Capacity", then tap "Create tank".
5. In "Pumps and nozzles", tap "New pump", then "Create pump". On the pump, tap "Add nozzle", pick the tank it draws from and tap "Create nozzle".
6. In "Settings", type the tolerances your company accepts, then tap "Save tolerances". They all start at zero, so every difference needs approval until you set them.

## Give a tank its calibration chart

A dip in cm becomes litres only through the tank's chart.

1. In "Tanks", open the tank.
2. Under "Calibration charts", tap "Add calibration chart".
3. Fill "Chart rows" from the tank's strapping table: a dip and its litres on each row. Tap "Add row" for more. Both columns must rise from one row to the next.
4. Leave "In force from" empty to start now, then tap "Save chart".
5. To test it, type a dip under "Dip to litres" and tap "Convert". Nothing is recorded.

A chart cannot be changed. Add a new one instead. An archived station, product, tank, pump or nozzle stays in the records; archive a tank's nozzles before the tank, and close the shift before archiving a station or nozzle.
