---
id: core-follow-document-flow
title: Follow a document through its workflow
module: core
order: 150
permissions: [core.workflow.view]
roles: [owner, admin]
screens: [/document-workflows/:documentType/:documentId, /settings/workflows/insights]
---
Every document that follows a workflow has a status page. Links from notifications and the automation run log open it.

1. On the page, read "Status" ("In progress", "Completed" or "Cancelled"), when it "Started" and the "Workflow version".
2. Under "Where it is", read the open step, who it is "Held by", how long it has been "In this step" and when it is "Due" or "Overdue".
3. Tap "Open the approval" to decide it, or "Open the document" to read the document.
4. Under "History", read every step in order: reached, left, returned, skipped, reminded or escalated.
5. If you may act on the flow, "Move on", "Return to an earlier stage" and "Cancel the flow" appear. Returning asks for a "Reason for returning". Cancelling closes the open steps and cannot be undone.

To see how documents move across all live workflows, open "Stage volumes" from the workflows list. Choose a "Document type", "Company" and dates. For each stage you see how many documents are "Now" in it, how many "Entered" and "Left", the "Median time", the "Slowest 10%" and how many are "Overdue". Times are elapsed time, not business hours, and count only documents you can see.
