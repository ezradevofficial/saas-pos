---
id: automation-manage-rules
title: Enable, disable or archive a rule and read its runs
module: core
order: 860
permissions: [core.automation.view, core.automation.edit]
roles: [owner, admin]
screens: [/settings/automation-rules]
---
1. Open Automation, then "Automation rules".
2. Search the list by name, or filter by status, document type and company.
3. Open a rule. Tap "Disable" to stop it or "Enable" to start it again.
4. Open the "Runs" tab to see every time the rule ran: when, on which document, the outcome and the attempts. Open a run to see which conditions passed and what each action did.
5. To retire a rule, tap "Archive rule", then "Archive rule now".

Warning: an archived rule stops running and can't be edited afterwards. Its run log is kept.

The outcomes include "Succeeded", "Skipped", "Failed", "Throttled" and "Loop blocked". The last stops a rule that would trigger itself in a loop. The run log of all rules is also under "Run log".
