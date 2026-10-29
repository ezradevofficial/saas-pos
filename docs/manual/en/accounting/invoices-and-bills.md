---
id: accounting-invoices-and-bills
title: Raise and post an invoice or a bill
module: accounting
order: 15
permissions: [accounting.invoice.view, accounting.invoice.create, accounting.invoice.post]
roles: [accountant, owner]
screens: [/accounting/documents, /accounting/documents/new, /accounting/documents/:documentId]
---
An invoice or a credit note goes to a customer; a bill or a supplier credit comes from a supplier. Posting makes the journal.

1. Open "Invoices and bills" under "Accounting". The "Customers" and "Suppliers" tabs list the documents. Search by number or reference, and filter by "Status", "From" and "To".
2. Tap "New invoice" on "Customers", or "New bill" on "Suppliers".
3. Choose the "Kind": for example "Invoice" or "Credit note". For a credit note or a supplier credit, choose the "Invoice credited".
4. Choose the "Contact", the "Currency", the "Issue date" and the "Due date". Leave "Due date" empty to use the contact’s payment terms. For a bill, type the "Supplier’s number".
5. Turn "Prices include tax" on if the unit prices already include tax.
6. Under "Lines", type a "Description", choose the "Account", type the "Quantity" and the "Unit price", and choose the "Tax". Tap "Add line" for more.
7. Tap "Save draft". The document is a "Draft" without a number.
8. Open it, check the lines, then tap "Post". It gets its number and becomes "Posted". Tap "Cancel draft" to drop a draft.

If a line shows "Rate needed", its tax code has no rate yet. Enter the confirmed rate in the tax settings, then post.

A posted document shows what is "Open", its "Journal" with "Open the journal", and its "Settlements". Tap "Print" to get a PDF. To settle a posted credit note against open invoices, tap "Settle invoices". To receive or pay money against an invoice, see "Record a receipt or a payment".
