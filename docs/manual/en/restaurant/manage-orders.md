---
id: restaurant-manage-orders
title: Follow and fix open orders and tabs
module: restaurant
order: 60
permissions: [restaurant.order.view, restaurant.order.transfer, restaurant.order.merge, restaurant.order.edit]
roles: [branch_manager]
screens: [/restaurant/orders]
---
See every open table and bar tab of a location, and fix one when a waiter cannot.

1. Open "Orders and tabs". Choose the location. Search by table, tab or waiter, and filter by status, kind ("Table" or "Tab") and waiter.
2. Open an order to read its "Lines", "Tickets" and "Payments". A line marked "Void after send" was removed after it reached the station. A flag such as "Table shared by two tills" marks an order to look at.
3. To hand the order to another waiter, tap "Transfer to waiter", choose the waiter and tap "Transfer order".
4. To join two orders, tap "Merge into another order", choose the order and tap "Merge orders".
5. To remove a line, tap "Void line", give the "Reason" and confirm. A sent line is also voided at the station and audited. If it is above your limit, ask a manager to approve it.
6. To cancel an order, tap "Cancel order", give the "Reason" and confirm. Only an order with nothing sent or paid can be cancelled.
7. To preview a split bill, tap "Split bill", choose "Split by" (whole bill, item groups, seat or equal parts) and tap "Show parts". Nothing is charged here; the waiter bills at the till.
