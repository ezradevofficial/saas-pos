---
id: data-export-request
title: Export all your data
module: core
order: 420
permissions: [core.tenant.export]
roles: [owner]
screens: [/settings/data-export]
---
The export is a complete copy of your organisation's data that other systems can read. Only the Owner can request it.

Warning: the file holds all of your business data, including customers, staff and finances. Store it in a safe place, share it with nobody who should not see everything, and delete your copy when you no longer need it.

1. Open Settings, then "Data export".
2. Read "What the export contains": every record as JSON Lines files, your files, and a manifest with a checksum per file. Secrets such as password hashes, device secrets and payment credentials are left out.
3. Tap "Request export".
4. Enter "Your password" and confirm.
5. Wait while the status shows "Preparing". The page updates when the export is "Ready".
6. Tap "Download". The file is available for a limited number of days, shown as "available until".

Up to a few exports can be requested in 24 hours, one at a time. An export that shows "Failed" could not be prepared; request a new one. Past exports are listed under "Past exports".
