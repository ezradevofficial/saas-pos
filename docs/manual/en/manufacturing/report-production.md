---
id: mfg-report-production
title: Report production and close an order
module: manufacturing
order: 5
permissions: [manufacturing.order.view, manufacturing.production.report, manufacturing.order.close]
roles: [branch_manager, owner]
screens: [/manufacturing/orders, /manufacturing/orders/:orderId]
---
Report what came out of the order as often as you need. Close it when the work is finished.

1. Open "Production orders" under "Manufacturing" and open an order in progress.
2. Tap "Report production".
3. Type the "Good" quantity and the "Scrap" quantity, in the product’s unit. If there is scrap, choose "Why it was scrapped": "Damaged", "Failed quality check", "Process loss" or "Other".
4. Under "By-products", type the quantity of each by-product that came out.
5. Type the "Labour minutes", or leave it empty to use the version’s minutes for the good quantity. Add a "Note" if useful.
6. Tap "Report production". The good output goes into stock, and the report is listed under "Production reports" with its cost.
7. When nothing more will be issued or reported, tap "Close order" and confirm. What is left in work in progress becomes the variance.

You can report again until the order is closed. If some output was not taken into stock, the page lists the items.
