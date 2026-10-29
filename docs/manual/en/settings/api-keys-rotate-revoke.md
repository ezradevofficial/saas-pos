---
id: api-keys-rotate-revoke
title: Rotate or revoke an API key
module: core
order: 380
permissions: [core.api_key.manage]
roles: [owner, admin]
screens: [/settings/api-keys]
---
Rotate a key to replace its secret without stopping the system that uses it. Revoke a key to cut that system off.

Warning: revoking can't be undone. Systems using the key stop working at once. Create a new key if you need one again.

To rotate:

1. Open Settings, then "API keys".
2. Tap "Rotate" beside the key.
3. Choose how long the previous secret keeps working, under "Previous secret keeps working for". This gives you time to update your systems.
4. Tap "Rotate key".
5. Copy the new key with "Copy key", store it, then tap "I have stored the key". It is shown once.

To revoke:

1. Tap "Revoke" beside the key.
2. Tap "Revoke key".

A revoked key shows "Revoked" in the list.
