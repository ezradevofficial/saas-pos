---
id: crm-create-quote
title: Create a quote
module: crm
order: 110
permissions: [crm.quote.view, crm.quote.create]
roles: [owner, branch_manager, sales_rep]
screens: [/crm/quotes/new, /crm/quotes, /crm/quotes/:quoteId/edit]
---
Price an offer for a customer. The quote stays a draft until you submit it.

1. Open "Quotes" and tap "New quote". From an opportunity, tap "New quote" in its "Quotes" card so the quote is linked to the deal.
2. Search the customer under "Search customers" and choose the "Branch".
3. Leave "Currency" on the customer's or company's currency, or choose one. Set the "Quote date" and "Valid until", or leave them empty for today and the company's validity.
4. Under "Lines", choose an "Item" or describe the line. Set the "Quantity". The "Unit price" comes from the price list; type a price to change it, or tap "Use list price" to go back.
5. Add a "Discount" if you need one and check the "Tax code". Tap "Add line" for more lines.
6. Add the "Terms" and "Notes". Check the "Totals" as they update.
7. Tap "Save draft".

A discount above your limit sends the quote for approval when you submit it. To change a draft later, open it and tap "Edit".
