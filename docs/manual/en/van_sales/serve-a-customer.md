---
id: van-serve-a-customer
title: Serve a customer on the route
module: van_sales
order: 20
permissions: [van_sales.visit.record, van_sales.collection.record, pos.sale.create]
roles: [van_rep]
screens: [pos:StopScreen, pos:CollectScreen]
---
At each stop, sell, collect what the customer owes, or record why nothing was sold.

1. On "Route day", tap the stop. To see someone off the route, tap "Visit another customer" and search by name or phone.
2. Check "Credit": the limit, balance and what is available.
3. To sell, tap "Sell" and ring the sale as usual. A credit sale above the available credit is refused.
4. To collect, tap "Collect payment". Choose the "Payment method", the "Currency" and the "Amount". For M-Pesa you may type the "M-Pesa code". Tap "Collect <amount>".
5. For goods coming back, tap "Return or exchange", choose the sale, and tap "Return items". For an exchange, then tap "Sell replacement".
6. If nothing is sold, tap "No sale", pick the "Reason" and tap "Record no sale". Tap "Skip stop" to pass the stop.

Receipts and sales upload when you are back online.
