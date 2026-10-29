---
id: marketplace-manage-installed
title: Review, approve again or uninstall an app
module: core
order: 650
permissions: [core.marketplace.view, core.marketplace.install]
roles: [owner, admin]
screens: [/settings/apps, /settings/apps/:installationId]
---
Installed apps lists the apps your organisation installed, what each was given and the role it acts as.

1. Open Settings, then "Installed apps".
2. Open an app to see "What it was given", its role and its "API use" over 30 days.
3. If it shows "Needs your approval", a new version asks for more. The app keeps what you approved until you decide. Tap "Review and approve", read what it now asks, and tap "Approve" or change its role first with "Change its role".
4. To remove an app, tap "Uninstall", then "Uninstall".

Warning: uninstalling ends the app's access at once and it stops receiving events. What it already created stays. A paid app is not billed from the next renewal.
