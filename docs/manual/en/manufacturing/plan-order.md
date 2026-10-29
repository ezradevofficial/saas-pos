---
id: mfg-plan-order
title: Plan and release a production order
module: manufacturing
order: 3
permissions: [manufacturing.order.view, manufacturing.order.create, manufacturing.order.release]
roles: [branch_manager, owner]
screens: [/manufacturing/orders, /manufacturing/orders/new, /manufacturing/orders/:orderId, /manufacturing/orders/:orderId/edit]
---
A production order says what to make, how much and where. The version of the BOM in force at the planned start gives the materials.

1. Open "Production orders" under "Manufacturing" and tap "Plan order". If the page says no product has a production BOM yet, make one first. See "Create a bill of materials or a recipe".
2. Choose the "Location", the "Product", type the "Quantity" and choose the "Unit".
3. Set the "Planned start" and the "Planned end", and add a "Note".
4. Tap "Plan order". The order gets a number and is "Planned". The page lists its "Materials".
5. While it is planned, tap "Edit" to change it, then "Save changes".
6. Tap "Release". If the company needs approval to release orders, the page says "Release sent for approval" and a branch manager decides it in the approvals inbox. See "Decide approvals". Materials can be issued once the order is released.

To stop an order, tap "Cancel order", type "Why it was cancelled" and confirm. A cancelled order cannot be reopened. Tap "Print" to get the production order or the pick list as a black-on-white document, or "Download PDF".

When Inventory is off, orders record quantities only: no stock moves and no costs.
