---
id: fuel-record-a-delivery
title: Record a fuel delivery
module: fuel
order: 35
permissions: [fuel.delivery.record, fuel.delivery.view]
roles: [shift_supervisor, station_manager]
screens: [/fuel/deliveries]
---
Record a tanker offloading into a tank. The stock is posted from what the dips measured, not from the delivery note, so dip the tank before and after.

1. Dip the tank before the tanker offloads and write down the reading in cm.
2. After offloading, dip the tank again and write down the reading.
3. Open "Fuel deliveries" and tap "Record a delivery".
4. Choose the "Station" if you work at more than one, then the "Tank".
5. Choose the "Supplier" if you know it.
6. Type the "Delivery note number" and the "Litres on the note".
7. Type the "Dip before" and the "Dip after", both in cm.
8. Type the "Cost per litre". It is in the station's currency, and stock is valued at it.
9. Tap "Record delivery". The message "Delivery FD-… recorded." shows the litres received. Tap "Open the delivery" to read it.

The litres received are the dip after minus the dip before, plus any litres sold while offloading. If they are less than the note, the delivery shows the shortage. It is recorded as measured; claim the shortage from the supplier outside {{app_name}}. A delivery cannot be changed afterwards. Ask a manager to correct a mistake with an inventory adjustment.
