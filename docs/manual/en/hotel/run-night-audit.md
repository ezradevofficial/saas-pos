---
id: hotel-run-night-audit
title: Run the night audit
module: hotel
order: 50
permissions: [hotel.audit.view, hotel.audit.run, hotel.charge.resolve]
roles: [night_auditor]
screens: [/hotel/night-audit, /hotel/charge-exceptions]
---
The audit posts the night's room charges, flags no-shows and moves the property to the next business date.

1. Open "Night audit". The page shows the business date.
2. Open "Room charges to resolve" first. For each charge, tap "Resolve", choose "How it is resolved", add a "Note" and confirm.
3. Back on "Night audit", tap "Run night audit", then "Run audit".
4. Read "Snapshot of the night": occupancy, no-shows, arrivals, departures and room revenue. Any problems are counted at the top.

The audit closes the date and cannot be run twice for it. It may also run on a schedule; "Night audits" lists the past ones and who ran them.
