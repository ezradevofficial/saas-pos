---
id: workflows-create-publish
title: Change how a document is approved and publish it
module: core
order: 830
permissions: [core.workflow.view, core.workflow.edit, core.workflow.publish]
roles: [owner, admin]
screens: [/settings/workflows]
---
A workflow sets the stages, approvals and actions a document type goes through, per company. Edit a draft, test it, then publish it. Nothing changes for documents until you publish.

1. Open Automation, then "Workflows".
2. Tap "Create workflow". Choose the "Document type" and the "Company", then tap "Create and open". The workflow starts as a draft copy of the default. To change an existing one, open it from the list.
3. Add steps from "Drag onto the flow": a stage, an approval, a condition, parallel steps, a notification, a document to create, and an end. Drag a step onto the flow and connect it to the next.
4. Select a step to change it in "Step settings". For an approval, choose who approves ("Who approves") and what happens if several people can ("If several people can approve"). The requester can never approve their own document.
5. Tap "Check for problems" to find problems. Fix those under "Problems to fix before publishing".
6. Tap "Test with a sample", enter sample values and the decision at each approval, and tap "Run the test" to see the path taken. Nothing is saved or sent.
7. The draft saves itself ("Draft saved"). When it has no problems, tap the "Publish" button with the next version number, then confirm.

New documents follow the published version from then on. Documents already in progress stay on the version they started with.
