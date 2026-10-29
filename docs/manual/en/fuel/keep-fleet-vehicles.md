---
id: fuel-keep-fleet-vehicles
title: Keep fleet vehicles
module: fuel
order: 55
permissions: [fuel.vehicle.view, fuel.vehicle.manage]
roles: [station_manager]
screens: [/fuel/vehicles]
---
Register the vehicles of credit customers. A credit sale checks the vehicle's fuel type and daily litre limit, and flags a sale that breaks them.

1. Open "Fleet vehicles". Search by registration or label.
2. Tap "Add vehicle".
3. Choose the "Customer" and type the "Registration". Add a "Label", for example the driver or the route.
4. Choose the "Fuel type" the vehicle may take, or leave "Any fuel". Type the "Daily litre limit", or leave it empty for no limit.
5. Tick "Blocked: a credit sale to this vehicle is flagged" to stop credit sales to it. Tap "Save vehicle".
6. To change a vehicle, tap "Edit" on its row. To remove it from new credit sales, tap "Archive". Tick "Show archived vehicles" to see them.

An archived vehicle is unknown to new credit sales. Its past sales stay in the records.
