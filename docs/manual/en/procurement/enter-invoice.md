---
id: procurement-enter-invoice
title: Enter and approve a supplier invoice
module: procurement
order: 12
permissions: [procurement.invoice.view, procurement.invoice.create, procurement.invoice.approve, procurement.invoice.override]
roles: [accountant, procurement_officer, owner]
screens: [/procurement/invoices, /procurement/invoices/:invoiceId]
---
Every supplier invoice is matched against the purchase order (price) and against what was received and not yet invoiced (quantity).

1. Open "Purchase orders" under "Procurement" and open an approved order. Tap "Enter invoice".
2. Type the "Supplier invoice number" and the "Invoice date". Set the "Due date" if you have it, and "Prices include tax" if it applies.
3. For each line, type the "Quantity invoiced" and the "Unit price", and choose the "Tax code".
4. Tap "Save and match". The invoice page opens with the "Three-way match": each line against the order and the goods received, within the company’s tolerances.
5. If every line matches, the invoice shows "Matched". Tap "Approve invoice". Approving freezes the rate and the amounts.
6. If at least one line differs, the invoice shows "This invoice does not match" and its lines show "Price variance" or "More than received". Someone allowed to approve exceptions taps "Approve with exception", types the "Reason for approving" and confirms. The reason is recorded. Without that right, ask them to approve it.

Tap "Cancel invoice" to stop one that is not approved; it is kept, marked cancelled. Open "Supplier invoices" under "Procurement" to search and filter them by status or supplier. The tolerances are set in "Procurement settings".
