---
id: crm-record-quote-answer
title: Record the customer's answer to a quote
module: crm
order: 140
permissions: [crm.quote.view, crm.quote.send]
roles: [owner, branch_manager, sales_rep]
screens: [/crm/quotes, /crm/quotes/:quoteId]
---
When a customer answers a sent quote, record it.

1. Open "Quotes" and tap the sent quote.
2. If the customer agreed, tap "Record acceptance". When the quote belongs to an opportunity, tick "Also mark <name> as won" to close the deal at the same time. Confirm with "Record acceptance".
3. If the customer refused, tap "Record decline", type the "Reason" and confirm.

The quote shows the date it was accepted or declined. An accepted quote can be turned into an invoice. See "Convert a quote to an invoice".
