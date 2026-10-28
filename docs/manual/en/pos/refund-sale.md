---
id: pos-refund-sale
title: Refund items from a sale
module: pos
order: 90
permissions: [pos.sale.view, pos.sale.refund]
roles: [cashier, branch_manager]
screens: [pos:SalesScreen, pos:OverrideDialog]
---
Use a refund when a customer brings back some or all items. You need an open shift.

1. Tap "Menu", then "Sales and returns".
2. Type the number from the receipt in "Receipt number" and tap "Find sale". You can also tap the sale in the list.
3. For each item the customer brings back, tap the plus button ("Return one more") until the quantity is right. Each line shows how many can still be returned.
4. Type the reason in "Reason".
5. Check the refund total. Choose how to pay back: the till offers its cash methods, in the currencies the sale was paid in.
6. Tap "Refund" with the amount.
7. If the till asks for "Manager approval", ask a manager to approve. See "Ask a manager to approve".
8. The till confirms the refund and the amount to give back. Give the customer that amount.

A sale with returned items shows "Partly returned". You cannot return more than was sold.

Your role needs the refund right and a refund limit. Without them, or above the limit, a manager must approve each refund.
