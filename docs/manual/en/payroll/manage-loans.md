---
id: payroll-manage-loans
title: Record and settle an employee loan
module: payroll
order: 8
permissions: [payroll.loan.view, payroll.loan.manage]
roles: [payroll_officer, owner]
screens: [/payroll/loans, /payroll/loans/:loanId]
---
Each run deducts the loan’s instalment until the balance is repaid. Interest is not computed.

1. Open "Loans" under "Payroll". Search with "Search loans" and filter by status such as "Active" or "Settled".
2. Tap "New loan".
3. Choose the "Employee" by number or name, and the "Kind": "Loan" or "Salary advance". Add a "Reference" if useful.
4. Type the "Principal" and the "Instalment". The "Currency" is the employee’s pay currency.
5. Set "Disbursed on". Set "First deduction on", or leave it empty to start with the next run.
6. Add a "Note" if useful, then tap "Record loan".

Open a loan to read its "Balance" and the "Ledger" of every entry. Entries are never changed; a mistake is reversed.

For an active loan:

- Tap "Edit", change the fields and tap "Save changes".
- Tap "Settle loan", choose "How it is settled", either "Deduct in full in the next run" or "Paid outside payroll", set the date and confirm. With the first choice, the next run deducts the whole balance.
- Tap "Cancel loan", type the "Reason" and confirm. Only a loan with nothing repaid yet can be cancelled; otherwise settle it.

Then the run takes the deduction on its own. See "Run payroll".
