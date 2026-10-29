---
id: crm-submit-quote
title: Submit a quote
module: crm
order: 120
permissions: [crm.quote.view, crm.quote.create]
roles: [owner, branch_manager, sales_rep]
screens: [/crm/quotes, /crm/quotes/:quoteId]
---
Submit a finished draft so it can be sent.

1. Open "Quotes" and tap the draft.
2. Check the lines and totals. If a banner says "A tax rate is needed", ask for the rate to be confirmed in Taxes first. "Submit" stays unavailable until then.
3. Tap "Submit".

If a discount is above your limit, or the total is above the company's approval threshold, the quote goes to a manager and shows "Waiting for approval". The manager decides it in Approvals. See "Decide approvals". Once approved, you can send it.
