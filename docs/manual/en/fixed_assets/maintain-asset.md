---
id: fa-maintain-asset
title: Plan and record asset maintenance
module: fixed_assets
order: 8
permissions: [fixed_assets.maintenance.view, fixed_assets.maintenance.manage]
roles: [branch_manager, accountant, owner]
screens: [/fixed-assets/maintenance, /fixed-assets/assets/:assetId]
---
Maintenance schedules say when an asset needs service. Work orders record the work.

1. Open an asset under "Asset register" and choose the tab "Readings and maintenance".
2. To log a meter reading, tap "Add reading", set the "Date", type the "Reading" and a "Note", and tap "Save reading".
3. To plan recurring service, tap "Add schedule". Type the "Name" and choose the "Basis": "By time" asks "Every (days)"; "By usage" asks "Every (units)". Set "Warn days before" and tap "Save schedule".
4. To record a job, tap "Add work order". Type the "Title", choose the "Schedule" if it belongs to one, set the "Due" date and tap "Save work order".
5. Open "Maintenance" under "Fixed assets" to follow all work. The tab "Work orders" lists each job with its status and due date; the tab "Schedules" lists the plans and their "Next due".
6. When the job is done, tap its row in "Work orders". Choose the "Status" ("Open", "In progress" or "Done"). For "Done", set "Completed on" and the "Reading", and type the "Cost". Tap "Save work order".

The asset’s history records the maintenance done.
