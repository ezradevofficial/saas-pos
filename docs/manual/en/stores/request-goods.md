---
id: stores-request-goods
title: Ask a store for goods
module: stores
order: 5
permissions: [stores.requisition.view, stores.requisition.create]
roles: [branch_manager, storekeeper, owner]
screens: [/stores/requisitions, /stores/requisitions/new, /stores/requisitions/:requisitionId]
---
A department asks a store for goods with a requisition. The department head approves it before the store issues anything.

1. Open "Requisitions" under "Stores" and tap "New requisition".
2. Choose the "Store" you ask and the "Department" that is asking. Choose the "Cost centre" if the goods are charged to one, and set "Needed by". Add a "Note" if useful.
3. Under "Lines", search and choose the "Item", choose the "Unit", type the "Quantity" and, if needed, a "Note for the store". Tap "Add a line" for more. Only stock items can be asked for.
4. Tap "Send for approval". The requisition gets a number and goes to the department head, who decides it in the approvals inbox. See "Decide approvals".
5. Follow it on the requisition page. Its status moves from "Waiting for approval" to "Approved", then "Partly issued" or "Issued".

To stop a request that is not yet fully issued, open it and tap "Cancel requisition". Nothing will be issued against it and its approval stops. The store then issues the goods: see "Issue goods to a department".
