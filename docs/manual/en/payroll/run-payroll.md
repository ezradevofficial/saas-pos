---
id: payroll-run-payroll
title: Run payroll
module: payroll
order: 10
permissions: [payroll.run.view, payroll.run.prepare, payroll.run.post, payroll.payment.export]
roles: [payroll_officer]
screens: [/payroll/runs, /payroll/runs/:runId]
---
Statutory values, such as tax bands, contribution rates and levies, come from the country pack and are never guessed. If one is missing, the run is refused and the page lists what is missing; the platform team confirms it in the pack.

1. Open "Runs" under "Payroll" and tap "New run".
2. Choose the "Company" and the "Pay group". Choose "A date in the period" and, if you need another, the "Pay date".
3. Tap "Create run", then "Calculate".
4. Read the totals, the comparison with the last posted run and the "Lines". Fix any line with errors, then tap "Calculate again". Errors block the next step.
5. Tap "Submit for approval". Approvers decide in their approvals inbox. The person who prepared or submitted the run cannot approve it.
6. When the run is approved, tap "Post run" and confirm. This locks the run and issues the payslips.
7. To pay, choose the "Format" and tap "Download payment file".

A posted run is never changed. To fix a mistake, tap "Correct run" and create a correction run. To drop a run that is not posted, tap "Cancel run".
