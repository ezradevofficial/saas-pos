---
id: accounting-post-journal
title: Enter and post a journal
module: accounting
order: 20
permissions: [accounting.journal.view, accounting.journal.create, accounting.journal.approve, accounting.journal.reverse]
roles: [accountant]
screens: [/accounting/journals, /accounting/journals/new, /accounting/journals/:journalId]
---
A manual journal records an entry that no other document makes for you.

1. Open "Journals" under "Accounting" and tap "New journal".
2. Choose the "Date". Add a "Reference" and a "Memo" if useful.
3. On each line, choose the "Account" and type an amount in "Debit" or "Credit". Add a "Description" if useful. For another currency, choose the "Currency" and check the rate.
4. Tap "Add line" until the entry is complete. Check that "Difference" shows "Balanced".
5. Tap "Save draft". Use "Edit" to change a draft, or "Cancel journal" to drop it.
6. Tap "Submit for approval". The journal shows "Waiting for approval"; tap "Open approvals" to follow it.
7. Once it is approved, tap "Post".

To undo a posted journal, open it, tap "Reverse", choose the "Reversal date" and confirm. A new journal swaps every line and the original stays as it is.
