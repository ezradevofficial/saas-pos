---
id: procurement-create-order
title: Create and send a purchase order
module: procurement
order: 8
permissions: [procurement.po.view, procurement.po.create, procurement.po.print]
roles: [procurement_officer, branch_manager, owner]
screens: [/procurement/orders, /procurement/orders/new, /procurement/orders/:orderId, /procurement/orders/:orderId/edit]
---
A purchase order is approved before it is sent to the supplier.

1. Open "Purchase orders" under "Procurement" and tap "New purchase order". To start from an approved requisition, open it and tap "Create purchase order": its branch and lines are filled in. Awarding an RFQ or a tender also makes draft orders.
2. Choose the "Branch" and the "Supplier". Choose the "Delivery location", set the "Order date", the "Expected date" and the "Currency".
3. Turn "Prices include tax" on if the unit prices already include tax. Off, the tax is added to them.
4. Under "Lines", choose the "Item", the "Unit", the "Quantity" and the "Unit price", and a "Tax code". Tap "Add a line" for more. Add "Terms" if useful.
5. Tap "Save purchase order". It is saved as a draft.
6. Open it and tap "Submit for approval". If a line shows "Rate needed", a tax code has no rate in force on the order date: add the rate in Taxes or choose another code first.
7. When the order is approved, tap "Send to supplier". The order is marked sent and its PDF is emailed. Leave "Email" empty to use the supplier’s email, or type another address.

Tap "Download PDF" to get a copy. Tap "Edit" while it is a draft. Tap "Cancel order" to stop it; it is kept, marked cancelled. From an approved order, "Receive goods" starts the delivery (see "Receive goods from a purchase order") and "Enter invoice" starts the supplier’s invoice (see "Enter and approve a supplier invoice").
