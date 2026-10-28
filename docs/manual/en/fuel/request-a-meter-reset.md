---
id: fuel-request-a-meter-reset
title: Request a meter reset
module: fuel
order: 42
permissions: [fuel.meter.request_reset]
roles: [shift_supervisor, station_manager]
screens: [/fuel/meter-resets]
---
Ask for a reset when a pump meter was replaced or rolled over to zero. Until a reset is approved, the meter's readings are not allowed to go down.

1. Read the last reading before the change, and the first reading after it.
2. Open "Meter resets" and tap "Request reset".
3. Choose the "Nozzle".
4. Under "Open shift", choose the shift the nozzle is on, if one is open. The litres before the change are added to that shift. Otherwise leave "No shift".
5. Type the "Reading before" and the "Reading after", both in litres. The reading after cannot be above the reading before.
6. Type the "Reason", for example that the meter was replaced.
7. Tap "Send request". The request shows in the list as "Waiting".

A different person, usually the station manager, approves or rejects it. You cannot approve your own request. See "Approve variances and meter resets". If the request is rejected, nothing changes on the meter.
