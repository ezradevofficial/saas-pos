---
id: pos-give-discount
title: Give a discount
module: pos
order: 40
permissions: [pos.sale.create, pos.discount.give]
roles: [cashier, branch_manager]
screens: [pos:LineDialog, pos:OverrideDialog]
---
A discount is taken off one line. Your role sets the largest discount you may give, as a percentage of the line.

1. Tap the line in "Current sale".
2. Type the amount in "Line discount". It cannot be higher than the line price.
3. Tap "Save line". The discount shows on the line and in the sale total.

If the discount is above your limit, or your role cannot give discounts, the till opens "Manager approval". A manager picks their name, types their PIN and taps "Approve". See "Ask a manager to approve".

Changing the quantity can push a discount over your limit. When that happens, the till removes the discount and says so. Ask a manager to give it again.

The cashier role has no discount right of its own, so a cashier's discounts need a manager. Admins can give a role a limit under Settings, Roles.
