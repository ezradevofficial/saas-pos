---
id: security-export-access-review
title: Export the access review
module: core
order: 210
permissions: [core.user.view, core.access_review.export]
roles: [owner, admin]
screens: [/settings/users]
---
The access review is a file that lists who has which role and where. Use it to check that access is right, for example every quarter or before an audit.

1. Open Settings, then "Users".
2. Tap "Export access review".
3. Open the downloaded file in a spreadsheet.

For each person, check the roles, the places they apply to and whether two-factor is on. Remove roles that are no longer needed (see "Give a person a role"), and deactivate people who have left (see "Deactivate a user or sign them out").

Every change to a role, permission or assignment is recorded in the audit log.
