---
id: hotel-read-hotel-reports
title: Read the hotel reports
module: hotel
order: 65
permissions: [hotel.report.view]
roles: [receptionist, night_auditor]
screens: [/hotel/reports/:kind]
---
Seven reports show how full the hotel is and who is where.

1. Open "Hotel reports" and choose the property.
2. Pick a tab: "Occupancy", "Arrivals", "Departures", "In house", "No-shows", "Folio balances" or "Housekeeping".
3. Choose the "Day". For "Occupancy", choose a start and end date. The end date must not be before the start date.
4. Read the table. "Occupancy" shows "Rooms available", "Rooms sold", "Occupancy", "Room revenue", "ADR" and "RevPAR", and its "Source": night audit, today or forecast.
5. On "Occupancy", tap "Download CSV" to keep a copy.
