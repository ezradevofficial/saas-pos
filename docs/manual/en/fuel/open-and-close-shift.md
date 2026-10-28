---
id: fuel-open-and-close-shift
title: Open and close a forecourt shift
module: fuel
order: 10
permissions: [fuel.shift.open, fuel.shift.close]
roles: [forecourt_attendant, shift_supervisor]
screens: [pos:FuelHome, pos:OpenFuelShift, pos:CloseFuelShift]
---
A shift starts with the opening meter readings and ends with the closing readings and what each attendant hands in.

1. Open the till menu and choose "Forecourt". If no shift is open, tap "Open shift".
2. Mark each nozzle "In use". Type its "Opening meter reading" and choose the "Attendant" who works it.
3. Each attendant confirms with their own PIN: tap "Confirm <name>", type the PIN and tap "Confirm <name>" again.
4. Add "Opening dips (optional)" if you measured the tanks. Tap "Open shift".
5. At the end, tap "Close shift". Type the "Closing meter reading" of every nozzle, and the "Pump test litres" if you poured any back.
6. Under "Handed in by each attendant", type the "Cash counted". Zero is allowed. Other tenders start from what the till recorded.
7. Add a "Note for the manager (optional)" and tap "Close shift". On "Shift closed", tap "Back to forecourt".

A reading lower than the last one is refused: check it, or ask a supervisor for a meter reset. The variance shown is only a preview; the server recounts and decides.
