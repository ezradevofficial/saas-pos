---
id: webhooks-test-rotate
title: Test a webhook and rotate its secret
module: core
order: 400
permissions: [core.webhook.manage]
roles: [owner, admin]
screens: [/settings/webhooks]
---
Warning: a test event and a new secret reach the outside system. Rotating keeps the old secret signing for a few hours, so update the receiving system before that time ends.

1. Open Settings, then "Webhooks".
2. Open the webhook from the list.
3. Tap "Send test event". The answer from the other system shows in the delivery log on the page.
4. To change the signing secret, tap "Rotate secret", then "Rotate secret". Copy the new secret with "Copy secret" and tap "I saved the secret".
5. To pause the webhook, tap "Switch off". Tap "Switch on" to resume.
6. To stop it for good, tap "Archive", then "Archive webhook". No more events are sent. Its delivery log is kept for 30 days.

A webhook that failed many times in a row shows "Switched off after failures". Check that the receiving system is running, then switch the webhook on again.
