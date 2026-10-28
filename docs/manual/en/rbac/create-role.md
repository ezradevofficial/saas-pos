---
id: rbac-create-role
title: Create a role and set its limits
module: core
order: 110
permissions: [core.role.view, core.role.create, core.role.edit]
roles: [owner, admin]
screens: [/settings/roles, /settings/roles/new, /settings/roles/:roleId]
---
System roles such as Cashier or Branch manager cannot be changed. Copy one to make your own version, or start from nothing.

## Copy a system role

1. Open Settings, then "Roles".
2. Tap "Copy" beside the role.
3. Confirm with "Copy role". The copy starts with the same permissions and is yours to change.

## Create a role

1. On "Roles", tap "Create role".
2. Type the "Role name" and a "Description".
3. In the permissions grid, tick what the role allows. Each row is a resource and each column an action.
4. To make people with this role set up two-factor sign-in, switch on "Require two-factor sign-in".
5. Tap "Create role".

## Set limits

1. Open the role.
2. Under "Limits", type a value for each limit: "Till discount" (a percentage), "Refund amount" and "Approval amount" (in the company's base currency). Leave a limit empty for none.
3. Tap "Save limits".

Above a limit, someone with a higher limit approves or overrides.

## Archive a role

Tap "Archive", then "Archive role". People who hold the role lose its permissions at once.
