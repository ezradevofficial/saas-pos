---
id: van-count-the-van
title: Count the van at the end of the day
module: van_sales
order: 30
permissions: [van_sales.reconciliation.submit]
roles: [van_rep]
screens: [pos:CountScreen]
---
Close the day by counting the stock, the cash and the mobile money.

1. On "Route day", tap "End of day count".
2. Under "Stock on the van", enter "Returned (good)" and "Damaged" for each item. The line shows load = sold + returned + damaged + variance. "Balanced" shows when it adds up.
3. Under "Cash and mobile money", type the amount you "Counted" for each currency and kind. Compare with "Expected".
4. Tap "Submit count".

If the count balances, the day closes. If it has a variance, it waits for supervisor approval; if it is rejected, count the van again and submit a new count. Without a connection the count is saved and uploads later.
