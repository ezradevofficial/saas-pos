---
id: restaurant-bill-and-pay
title: Bill a table and take payment
module: restaurant
order: 40
permissions: [restaurant.order.close]
roles: [waiter, bartender]
screens: [pos:BillScreen]
---
Close the order, split it if the guests ask, and charge each part.

1. On the order, tap "Request bill" when the guests ask for it, then tap "Bill and pay".
2. Read "Items", the service charge and the "Total".
3. Choose a "Tip": "No tip", a preset, or "Other amount".
4. Under "How to pay", choose "Whole bill", "By item", "By seat" or "Equally". For "Equally" set "Number of parts". For "By item" put each item in a group.
5. Tap "Charge <amount>" for each part and take the payment.
6. When "Order paid" shows, tap "Back to tables". The table is free again.

Once a part is paid, the split can no longer change. If the service charge or tip item is not set, ask an admin to set it, then sync the till.
