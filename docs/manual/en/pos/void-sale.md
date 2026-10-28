---
id: pos-void-sale
title: Void a sale
module: pos
order: 100
permissions: [pos.sale.view, pos.sale.void]
roles: [cashier, branch_manager]
screens: [pos:SalesScreen, pos:OverrideDialog]
---
Voiding cancels a whole sale. Use it for a mistake made in the same shift. For a sale from an earlier shift, refund the items instead.

1. Tap "Menu", then "Sales and returns".
2. Find the sale by "Receipt number" and tap "Find sale".
3. Type the reason in "Reason".
4. Tap "Void the whole sale".
5. A manager approves with their PIN in "Manager approval", unless your role can void. See "Ask a manager to approve".

The till then confirms the void, and the sale shows "Voided".

You cannot void:

- a sale from another shift, because only the open shift's sales can be voided;
- a sale that has returned items. Return the rest of the items instead;
- a sale that is already voided.
