---
id: accounting-record-payment
title: Record a receipt or a payment
module: accounting
order: 17
permissions: [accounting.payment.view, accounting.payment.create]
roles: [accountant, owner]
screens: [/accounting/payments, /accounting/payments/:paymentId]
---
A receipt is money from a customer; a payment is money to a supplier. Record it against the bank account it went through and allocate it to the invoices it settles.

1. Open "Receipts and payments" under "Accounting". Search by number or reference, and filter by "Kind", "From" and "To". "Unallocated" shows what is not yet matched to an invoice.
2. Tap "Record a payment".
3. Choose the "Kind": "Receipt" or "Payment".
4. Choose the "Contact" and the "Bank account". Set the "Date" and type the "Amount".
5. Type the "Reference" the bank or M-Pesa gives, so the statement line matches later.
6. Under "Open invoices", type in "Allocate" the part of the amount that settles each invoice. If nothing is open for the contact, you see "Nothing is open for this contact in this currency."
7. Tap "Record receipt" or "Record payment".

Tap a row to open it. The payment shows its "Journal" with "Open the journal", and under "Settlements" the invoices it settled, or "Nothing allocated yet." When the payment is in another currency, an "Exchange difference" appears. To match it to a bank statement, see "Reconcile a bank or mobile money account".
