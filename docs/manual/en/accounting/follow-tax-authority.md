---
id: accounting-follow-tax-authority
title: Follow documents sent to the tax authority
module: accounting
order: 60
permissions: [core.fiscal.view, core.fiscal.edit]
roles: [accountant]
screens: [/settings/fiscal]
---
Every sale, refund and void is sent to KRA eTIMS or the DRC DGI, depending on the company’s country. This page shows what the authority answered.

1. Open "Tax authority" under "Settings".
2. Under "Documents sent", filter by "Status": "Not sent yet", "Accepted", "Rejected", "Needs attention", "Retrying", "Queued" or "Sending". Use "Search document number" to find one document.
3. Read "Attempts" and "Last error" for a document that is not accepted.
4. Fix the cause named in the error, then tap "Retry" on its row. A document that is retrying also shows when the next try is.

Documents made before sending was switched on are not sent on their own. To send them, tap "Send earlier sales", choose the first day, tick the confirmation and tap "Send to the authority". The connection, keys and codes are set under "Settings" on the same page by an owner or administrator.
