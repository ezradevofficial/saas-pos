---
id: security-require-two-factor
title: Require two-factor sign-in for a role
module: core
order: 170
permissions: [core.role.view, core.role.edit]
roles: [owner, admin]
screens: [/settings/roles, /settings/roles/:roleId]
---
Two-factor sign-in asks for a second code after the password. You turn it on per role. System roles are not edited, so copy one first if you want a changed version.

1. Open Settings, then "Roles".
2. Open the role. If it is a system role, tap "Copy" and confirm with "Copy role", then open the copy.
3. Switch on "Require two-factor sign-in".
4. The page confirms "Two-factor sign-in is now required for this role."

People with this role must set up two-factor sign-in before they can continue. See "Set up two-factor sign-in". To turn it off again, switch it off. The "Roles" list shows "Required" in the "Two-factor" column.

The "Users" list shows "On" or "Off" in the "Two-factor" column for each person.
