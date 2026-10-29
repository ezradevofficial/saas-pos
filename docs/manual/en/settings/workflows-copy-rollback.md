---
id: workflows-copy-rollback
title: Copy a workflow to another company or go back to an earlier version
module: core
order: 840
permissions: [core.workflow.view, core.workflow.edit, core.workflow.publish]
roles: [owner, admin]
screens: [/settings/workflows]
---
1. Open Automation, then "Workflows".
2. Open the workflow from the list.
3. To copy it, open "More", then "Copy to another company". Choose the company and whether to copy the live version or the draft, then tap "Copy workflow". The copy becomes that company's draft. Its live workflow doesn't change until someone publishes the draft.
4. To go back to an earlier version, open "Versions", pick the version and tap its roll back button. A copy of that version is published as the new live version.
5. To start over from the document type's default, open "More", then "Restore the default". This replaces the draft only; the live version keeps running until you publish.
6. To drop unpublished changes, open "More", then "Discard draft".

Use "Stage volumes" to see how many documents sit in each stage.
