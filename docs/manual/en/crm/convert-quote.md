---
id: crm-convert-quote
title: Convert a quote to an invoice
module: crm
order: 150
permissions: [crm.quote.view, crm.quote.convert]
roles: [owner, branch_manager, accountant]
screens: [/crm/quotes, /crm/quotes/:quoteId]
---
Turn an accepted quote into a draft invoice.

1. Open "Quotes" and tap the accepted quote.
2. Tap "Convert to invoice".
3. The quote now shows "Converted to" and an "Open the invoice" link. Open the invoice to check and post it.

The button is available only once the quote has been accepted.
