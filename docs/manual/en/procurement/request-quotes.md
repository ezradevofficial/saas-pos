---
id: procurement-request-quotes
title: Request quotes from suppliers
module: procurement
order: 2
permissions: [procurement.rfq.view, procurement.rfq.create]
roles: [procurement_officer, owner]
screens: [/procurement/rfqs, /procurement/rfqs/new, /procurement/rfqs/:rfqId]
---
A request for quotation (RFQ) asks several suppliers for prices. You type in their quotes as they come back.

1. Open "Requests for quotation" under "Procurement" and tap "New RFQ". To start from an approved requisition, open it and tap "Request quotes" instead: its branch and lines are filled in.
2. Choose the "Branch" and type the "Title".
3. Set the "Response deadline" in the company’s time zone and choose the "Invited suppliers". Add a "Note" if useful.
4. Add the lines you want priced: the "Item" or "Description", the "Unit" and the "Quantity".
5. Tap "Save RFQ". It is saved as a draft.
6. Open it and tap "Issue RFQ".
7. When a supplier answers, open the RFQ and tap "Enter quote". Choose the "Supplier", type their "Reference", the "Quote date" and "Valid until", and check the "Currency".
8. Under "Prices per line", type the "Unit price" and the "Lead time" in days for each line. Leave a price empty when the supplier offers nothing for that line.
9. Tap "Save quote". A second quote from the same supplier replaces the first.

To stop the request, tap "Cancel RFQ". It is kept, marked cancelled. See "Compare quotes and award an RFQ" for the next step.
