---
id: fuel-check-tank-dips
title: Check and record tank dips from the office
module: fuel
order: 32
permissions: [fuel.dip.view, fuel.dip.record]
roles: [shift_supervisor, station_manager]
screens: [/fuel/dips]
---
Read the dips of each tank and record one from the back office. A dip is converted to litres by the calibration chart in force.

1. Open "Tank dips". Filter by station, tank, kind and the "From" and "To" dates.
2. Tap "Record a dip".
3. Choose the "Tank". Type the "Dip" as read on the stick, in cm. Type the "Water" in cm if you measured it. Leave "Taken at" empty for now.
4. Tap "Record dip". The server shows the litres and what happens next:
   - the first dip of a tank only sets its baseline;
   - a difference within tolerance is accepted and the baseline moves to this dip;
   - a difference outside tolerance waits for a station manager. You cannot approve your own dip.
5. If the dip is reconciled, tap "Open the reconciliation".

The tank needs a calibration chart first. See "Set up the forecourt". At the till, use "Record a tank dip".
