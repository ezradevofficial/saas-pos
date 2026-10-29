---
id: hotel-keep-guest-records
title: Keep guest records
module: hotel
order: 25
permissions: [hotel.guest.view, hotel.guest.manage, hotel.guest.identity]
roles: [receptionist]
screens: [/hotel/guests, /hotel/guests/:guestId]
---
Guest records hold personal data. ID numbers are masked until someone with permission shows them.

1. Open "Guests". Search by name, phone or email.
2. Open a guest to read the "Details" and see their stays with "See this guest's stays".
3. To change a record, tap "Edit guest". Update the "Phone", "Email", "Nationality", "ID type", "ID or passport number", "Date of birth", "Address" or "Notes", then tap "Save guest". Leave the ID number empty to keep the one on record.
4. To read an ID number in full, tap "Reveal". The view is recorded in the audit log. Tap "Hide" when done.
