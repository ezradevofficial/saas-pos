---
id: pos-manager-approval
title: Ask a manager to approve
module: pos
order: 80
permissions: [pos.discount.give, pos.price.override, pos.sale.void, pos.sale.refund, pos.cash.move]
roles: [cashier, branch_manager]
screens: [pos:OverrideDialog]
---
Some actions need a manager when you do not have the right yourself, or when the amount is above your limit: a discount, a price change, a void, a refund, and paying cash in or out. The till opens "Manager approval" and explains why.

A manager approves at the same till. The manager does not need to sign in first.

1. Ask a manager to come to the till.
2. In "Manager approval", the manager taps their own name.
3. The manager types their PIN.
4. The manager taps "Approve".

If the PIN is wrong, the till shows how many tries are left. To use another person, tap "Choose someone else". If you change your mind, close the window. The till says "Not approved. Nothing was changed."

A manager's PIN must have 6 digits. If the till says that no one can approve, ask an admin to give a manager a role at this location, then tap "Sync now".
