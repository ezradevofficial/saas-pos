---
id: procurement-award-rfq
title: Compare quotes and award an RFQ
module: procurement
order: 3
permissions: [procurement.rfq.view, procurement.rfq.create, procurement.po.view]
roles: [procurement_officer, owner]
screens: [/procurement/rfqs, /procurement/rfqs/:rfqId]
---
Once quotes are in, the RFQ page compares them line by line.

1. Open "Requests for quotation" under "Procurement" and open an issued RFQ.
2. Read "Quote comparison". Unit prices are shown in the base currency at each quote date’s rate. The cheapest offer on a line is marked "Lowest", and the lead time is shown under each price.
3. Check "Totals per supplier". "Lines quoted" tells you whether a supplier priced every line.
4. For each line, choose the supplier you retain. A supplier may win some lines and lose others.
5. Tap "Award and create purchase orders". The RFQ is awarded and one draft purchase order is made for each supplier you chose. Links to them appear at the top of the page.
6. Open each draft order, check it and submit it for approval. See "Create and send a purchase order".
