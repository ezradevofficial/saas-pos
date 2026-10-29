---
id: webhooks-add
title: Add a webhook
module: core
order: 390
permissions: [core.webhook.manage]
roles: [owner, admin]
screens: [/settings/webhooks]
---
A webhook sends events from {{app_name}} to another system as they happen. Each event is signed so that system can check it came from here.

Warning: events leave {{app_name}} for the address you enter. Use only an https:// address you control, and send only the events that system needs. The signing secret is shown once.

1. Open Settings, then "Webhooks".
2. Tap "Add webhook".
3. Enter the "Endpoint URL", a public https:// address. Add a "Description" so your team knows what receives the events.
4. Under "Events", tick the events to send. Only events of the modules you use are listed.
5. Under "Send events of", choose "The whole organisation", "One company" or "One branch".
6. Tap "Add webhook".
7. Tap "Copy secret", store the secret in the receiving system, then tap "I saved the secret". It is shown only once.

Open the "Developer guide" tab to see how the receiving system checks the signature and what happens on a failure.
