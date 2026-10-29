---
id: stores-acknowledge-issue
title: Acknowledge goods received from the store
module: stores
order: 20
permissions: [stores.issue.view, stores.issue.acknowledge]
roles: [branch_manager, owner]
screens: [/stores/issues, /stores/issues/:issueId]
---
When the store issues goods, the department confirms it received them. The store keeps who confirmed and when.

1. Open "Issues" under "Stores". Issues that wait for the department show "Issued"; confirmed ones show "Acknowledged".
2. Open the issue and check its lines against what you received.
3. Tap "Acknowledge receipt", then confirm in the dialog.
4. The issue then shows who received it, under "Received by".

To get a paper copy, tap "Print" on the issue and choose "Issue voucher" or "Delivery note"; tap "Download PDF" for a file. Both print black on white. Use "Search by number or note" and the filters "Store" and "Status" to find an issue. If the goods are valued by Inventory, values show once it has valued them.
