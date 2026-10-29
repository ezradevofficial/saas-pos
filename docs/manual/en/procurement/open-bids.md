---
id: procurement-open-bids
title: Open and score the bids of a tender
module: procurement
order: 6
permissions: [procurement.tender.view, procurement.tender.open, procurement.tender.evaluate]
roles: [branch_manager, owner]
screens: [/procurement/tenders, /procurement/tenders/:tenderId]
---
Bids open only when two committee openers confirm, at or after the opening time. Evaluators then score the opened bids.

1. Open "Tenders" under "Procurement" and open the published tender.
2. Under "Committee opening", read "Openers": each shows "Confirmed" or "Not confirmed yet". A tender with fewer than two openers cannot be opened.
3. If you are an opener, tap "Confirm opening" once the opening time has passed. Your confirmation is recorded. The bids open at the second confirmation, by a different opener.
4. Under "Opened bids", read each bid: its prices, its "Technical response" and its files (tap "Download"). A bid that does not price every line is flagged. A bid changed after it was sealed shows "This bid was changed after it was sealed": it is not opened and cannot be evaluated.
5. If you are an evaluator, use the "Score sheet". For each opened bid, type your score for every criterion, add a comment if you wish and tap "Save scores for" that bidder. A bid’s technical score is the weighted mean over the evaluators.

The "Ranking" then shows each bid’s technical, financial and combined score and its outcome. See "Award a tender".
