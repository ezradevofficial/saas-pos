---
id: restaurant-change-order
title: Void, transfer or merge an order
module: restaurant
order: 30
permissions: [restaurant.order.edit, restaurant.order.transfer, restaurant.order.merge]
roles: [waiter, bartender]
screens: [pos:OrderScreen]
---
Fix an order after it has started.

1. To remove an item that was not sent yet, tap its line, then tap "Remove line".
2. To remove an item already sent, tap its line. Type a "Reason", then tap "Void item". The item stays on the order marked void and the station is told. If the void is "Not approved", the item stays.
3. To hand a table or tab to a colleague, tap "Transfer" and pick the person.
4. To join two tables, tap "Merge" and pick the table that receives the items.
5. To drop an order with nothing sent, tap "Cancel order".

A void or transfer is recorded with your name. A table with a part already paid cannot be merged.
