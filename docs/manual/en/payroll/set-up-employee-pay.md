---
id: payroll-set-up-employee-pay
title: Set up an employee’s pay
module: payroll
order: 5
permissions: [payroll.employee.view, payroll.employee.manage]
roles: [payroll_officer, owner]
screens: [/payroll/employees, /payroll/employees/:employeeId]
---
Before a run can pay someone, they need a pay group. Basic pay comes from the contract in HR and is never typed again in payroll.

1. Open "Pay setup" under "Payroll". The list shows each employee’s "Pay group", "Currency", "Payment method" and "Status".
2. If an employee is missing, tap "Sync from HR" followed by the company name. Employees come from HR.
3. Search with "Search employees". Use "Pay group assigned" and choose "No pay group yet" to find who is left out of runs.
4. Tap an employee. "Contract in force" shows the pay from HR. If it says "No contract in HR yet", add the contract in HR: runs pay nothing until then.
5. Under "Pay setup", choose the "Pay group". Employees without a pay group are left out of runs.
6. Keep the "Pay currency" as the contract’s currency unless pay goes out in another one. Choose the "Payment method". Type the "Dependants" and "Children" as whole numbers from 0 to 50.
7. Turn on "Hold pay (leave out of runs)" to leave the person out until you lift the hold.
8. Tap "Save pay setup".

To pay an allowance or hold back a deduction, use "Pay items" on the same page:

1. Tap "Add item", choose the "Pay item" and the "Amount" with its "Currency".
2. Choose how it is "Paid": "Every period", from the "From" date and until the "Until" date (leave "Until" empty to keep paying it), or "Once", in the period holding "A date in the period".
3. Add a "Note" if useful, then tap "Save item".

Tap "Edit" to change an item or "Archive" to stop it. Runs already posted keep it. If amounts show as hidden, your role may not see pay.
