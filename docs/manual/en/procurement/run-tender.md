---
id: procurement-run-tender
title: Prepare and publish a tender
module: procurement
order: 4
permissions: [procurement.tender.view, procurement.tender.create]
roles: [procurement_officer, owner]
screens: [/procurement/tenders, /procurement/tenders/new, /procurement/tenders/:tenderId, /procurement/tenders/:tenderId/edit]
---
A tender collects sealed bids. A committee opens them together and scores them before the award.

1. Open "Tenders" under "Procurement" and tap "New tender". To start from an approved requisition, open it and tap "Start a tender".
2. Choose the "Branch", type the "Title" and choose the "Kind": "Open" lets anyone with the public notice link register and bid; "Invited" lets only the bidders you invite bid.
3. Set the "Response deadline" and the "Opening time". The opening time is not before the deadline.
4. Set the "Technical weight" and the "Technical pass mark" (out of 100). The financial weight is what remains. Bids under the pass mark are disqualified.
5. Under "Technical criteria", tap "Add a criterion" and type each "Criterion", its "Weight" and its "Maximum score". The weights add up to 100.
6. Under "Committee", choose the "Openers" (at least two) and the "Evaluators". They need the tender permissions at the branch.
7. Add the lines to be priced, then tap "Save tender". It is saved as a draft.
8. Open it and tap "Publish tender". The lines, criteria and times are then fixed.

After publishing:

- For an open tender, tap "Copy link" under "Public notice" and share it. Suppliers register there and receive their own link by email.
- For an invited tender, or to add a supplier to an open one, tap "Invite bidder" under "Bidders". Choose "A supplier in your contacts" or "Another company", then tap "Send link". The supplier’s email is needed.
- Tap "Resend link" for a bidder who lost the email, or "Remove" to cancel their link.

Until the opening, "Bids" shows only how many bids arrived. Their content is sealed. To stop a tender, tap "Cancel tender": bidders’ links stop working and nothing is awarded.
