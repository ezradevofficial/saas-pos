---
id: api-keys-create
title: Create an API key
module: core
order: 370
permissions: [core.api_key.manage]
roles: [owner, admin]
screens: [/settings/api-keys]
---
An API key lets another system use the public API. Each key acts with one role at one place, and its scopes narrow it further. An Owner role can't be given to a key.

Warning: anyone who holds the key can act as it. The key is shown once, when you create it. Store it in your system's secret store and never share it in email or chat.

1. Open Settings, then "API keys".
2. Tap "Create key".
3. Type a "Name" after the system that uses it, such as Accounting sync.
4. Under "Role for this key", choose the role and the place it applies to. The key can do only what this role allows there.
5. Under "Scopes", choose "Read" or "Read and write" for each resource the system needs. Use "Find a resource" to find one.
6. If the key should stop working on a date, set "Expires on". Leave it empty for a key that does not expire.
7. Tap "Create key".
8. Tap "Copy key", store the key, then tap "I have stored the key". It is not shown again.

The list shows each key's "Status", "Last used" and expiry. "Requests per day" charts its requests over the last 30 days.
