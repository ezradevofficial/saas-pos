---
id: developer-apps-submit
title: Register as a developer and submit an app
module: core
order: 660
permissions: [core.developer.manage]
roles: [owner, admin]
screens: [/settings/developer-apps, /settings/developer-apps/:appId]
---
Build apps for the marketplace. The platform team reviews each version before it is listed.

1. Open Settings, then "Developer apps".
2. The first time, fill in "Public name" and contact details, accept the marketplace terms and tap "Register".
3. Tap "New app", fill in the app's details and tap "Create app".
4. Copy the credentials shown ("Client id", "Client secret" and "Webhook signing secret"), store them safely, then tap "I have copied them". They are shown once.
5. In the draft version, fill in the "Description", "Support email", "Redirect URIs", "Permissions" the app needs and its "Pricing". Tap "Save draft".
6. When the draft is complete, tap "Submit for review".

Until the review ends, the version shows "In review". You can tap "Withdraw from review" to take it back. A rejected version shows the reviewer's note.

Warning: client and webhook secrets give access to your app's connections. Rotating a client secret stops the old one at once.
