---
id: pos-sell
title: Sell items
module: pos
order: 30
permissions: [pos.sale.create, pos.sale.view]
roles: [cashier, branch_manager]
screens: [pos:SellScreen, pos:PhoneCartScreen]
---
Build the sale on the sell screen, then charge it. You need an open shift.

1. Tap a product to add it to "Current sale". Tap it again to add one more.
2. To find a product, type its name or code in "Search products", or tap a category. "All" shows every product.
3. To scan a barcode, tap "Scan". The first time, tap "Allow the camera".
4. To change a line, tap it. Set "Quantity", then tap "Save line". "Remove from sale" takes the line out.
5. To add a customer, tap "Add a customer" and search by "Name or phone number". Tap "Change" to pick someone else.
6. Check the totals: subtotal, discount, tax and total.
7. Tap "Charge" with the amount shown. Then take the payment.

## Hold a sale

To serve someone else first, tap "Hold". Tap "Held sales" at the top to find it, then tap "Resume". "Delete" removes a held sale.

## Start over

"Clear" empties the sale.

## Limits

- Changing "Unit price" needs a manager. See "Ask a manager to approve".
- An item shown as "No price", "Rate needed" or "Not for sale" cannot be sold until the back office fixes it. Remove it to charge the rest.
- If selling is paused for an unpaid subscription, the till says so. Ask the Owner to pay the invoice.
