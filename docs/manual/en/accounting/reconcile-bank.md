---
id: accounting-reconcile-bank
title: Reconcile a bank or mobile money account
module: accounting
order: 30
permissions: [accounting.bank.view, accounting.bank.reconcile]
roles: [accountant]
screens: [/accounting/bank, /accounting/bank/:bankId]
---
Reconciling matches the lines of a statement with the ledger.

1. Open "Bank and mobile money" under "Accounting" and open the account.
2. Tap "Import statement". Choose the "Format": "CSV file" or "M-Pesa statement". For a CSV file, say which column holds the date, description, reference and amount.
3. Choose the "File" and tap "Import statement". For M-Pesa, use the business statement export; only completed transactions are imported.
4. Tap "Match what is certain". Lines that match by amount, or that a posting rule covers, are matched for you.
5. For the lines left, tap a statement line, then tap the ledger lines it matches. When "Chosen" equals the statement amount, tap "Match".
6. For a line with no ledger entry, such as a bank fee, choose the account in "Post to" and tap "Post and match".

The summary shows the "Statement balance", the "Ledger balance" and what is still unmatched on each side.
