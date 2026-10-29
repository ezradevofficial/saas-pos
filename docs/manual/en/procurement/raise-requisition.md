---
id: procurement-raise-requisition
title: Raise a purchase requisition
module: procurement
order: 1
permissions: [procurement.requisition.view, procurement.requisition.create]
roles: [branch_manager, procurement_officer, storekeeper, owner]
screens: [/procurement/requisitions, /procurement/requisitions/:requisitionId, /procurement/requisitions/:requisitionId/edit, /procurement/requisitions/new]
---
A requisition is a request to buy goods or services. It is approved before anything is bought.

1. Open "Requisitions" under "Procurement" and tap "New requisition".
2. Choose the "Branch". Choose the "Location" and the "Department" if they apply, and set "Needed by" and "Currency".
3. Type the "Purpose" so the approver knows why.
4. Under "Lines", choose the "Item", or type a "Description" when the thing is not an item. Choose the "Unit" and type the "Quantity" and the "Estimated unit price". Add the "Cost centre" and "Project" if you use them. Tap "Add a line" for more.
5. Tap "Save requisition". It is saved as a draft.
6. Open the draft and tap "Submit for approval". The approver decides it in the approvals inbox. See "Decide approvals".

While it is a draft, tap "Edit" to change it. While it waits for approval, tap "Cancel requisition" to stop it. The requisition is kept, marked cancelled.

Once it is approved, the requisition page offers the next step: "Request quotes", "Start a tender" or "Create purchase order". The new document starts with the branch and lines of the requisition.
