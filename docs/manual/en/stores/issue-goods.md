---
id: stores-issue-goods
title: Issue goods to a department
module: stores
order: 10
permissions: [stores.requisition.view, stores.issue.view, stores.issue.create, stores.return.create]
roles: [storekeeper, branch_manager]
screens: [/stores/requisitions/:requisitionId, /stores/issues/:issueId]
---
A department asks the store with a requisition. Its head approves it, then the store issues the goods.

1. Open "Requisitions" under "Stores". Open an approved requisition.
2. In "Issue goods", read "still open" for each line.
3. Type in "Issue" the quantity you hand over. Lines left empty are not issued.
4. Tap "Issue goods". The goods are issued under a new number.
5. To print the "Issue voucher" or the "Delivery note", open the issue under "Issues" and tap "Print".
6. The department confirms with "Acknowledge receipt".

If goods come back, open the issue, tap "Return goods", type the quantities in "Return" and tap "Return to store". To stop issuing against a requisition, tap "Close requisition".
