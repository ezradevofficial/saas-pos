# Procurement module spec (phase 7, stage 7B)

Source: concept note sections 5 (Procurement row and the integration rule),
6.3 (the procurement process flow, three-way match, auto-create next
document) and 6.4 (purchasing in any currency, landed costs); platform core
spec (TEN-01, TEN-06, RBAC-01 to RBAC-09, MD-01 to MD-05, CUR-02 to CUR-04,
WF-01, WF-02, WF-08, WF-10, APR-01, APR-07, NUM-01, TPL-01 to TPL-04,
CF-01 to CF-06, LAY-04, NOT-02, AUD-01 to AUD-03, EXP-01, EXP-02, API-03);
ADR 002 (RLS), ADR 003 (money), ADR 008 (lists), ADR 009 (system
notifications). Built on the Inventory (`GoodsReceived`, INV-15) and
Accounting (ACC-07 source contract) specs. When this file and the core spec
disagree, the core spec wins.

## Scope

Procurement is a module (`api/modules/Procurement`, permissions
`procurement.*`, catalogue key `procurement`), sold and switched on per
tenant (RBAC-08). It works with only the core installed: it reads core
parties (the supplier role, MD-01), items and units (MD-02), tax codes
(MD-03), dimensions (MD-05), currencies and rates (CUR-), and keeps every
procurement fact in its own `procurement_*` tables. It never reads another
module's tables: Inventory and Accounting hear about it through events
(Integrations).

The full flow is concept note 6.3's: **requisition → RFQ or tender →
quotes or bids → purchase order → goods received note → supplier invoice**,
with landed costs on receipts and supplier performance computed from what
happened. Every step can also start on its own (a PO without a
requisition, a GRN against any approved PO).

- **Money** is `bigint` minor units with its currency (ADR 003); rates are
  `numeric(18,8)` snapshots (`FxSnapshot`, CUR-04); base amounts are in the
  company's base currency, locked on first use (`BaseCurrencyLock`, CUR-02).
- **Quantities** are `numeric(20,6)` decimal strings in the line's unit.
- **Suppliers** are core parties with the `supplier` role, shared or of the
  document's company (TEN-08). Outside bidders of an open tender are
  "bidders" until awarded (PRC-08).

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| PRC-01 | **Settings per company.** Three-way match tolerances: unit price (percent, default 0) and quantity (percent, default 0); the over-receipt allowance (percent of the ordered quantity a GRN may receive, default 0). Kept in `procurement_settings`, edited with `procurement.settings.edit`. | Must |
| PRC-02 | **Purchase requisitions with approval.** A requisition at a branch (optionally a location and department) lists what is needed: item or free description, unit, quantity, estimated unit price in a currency, needed-by date, cost centre and project (MD-05, of the same company). Drafts are edited; submitting numbers it and starts the `procurement.requisition` flow (WF-01; default WF-02: one approval by a Branch Manager at the branch, escalated to the Admins after two business days; APR-07 never the requester). Statuses `draft`, `pending`, `approved`, `rejected`, `cancelled`, `ordered`. An approved requisition becomes an RFQ, a tender or a draft PO. | Must |
| PRC-03 | **RFQs.** A request for quotation at a branch, from an approved requisition or blank: lines (item or description, unit, quantity), invited suppliers (core parties with the supplier role), a response deadline. `draft` → `issued` → `closed` (or `cancelled`). Quotes come back by email or paper and are entered by the buyer (PRC-04); the portal is for tenders (PRC-07). | Must |
| PRC-04 | **Supplier quotes.** One quote per invited supplier per RFQ, in any active currency: per RFQ line a unit price (or no offer), lead time in days, the quote's validity date and reference. Each price is also kept in base currency at the company's rate on the quote date (CUR-04). | Must |
| PRC-05 | **Quote comparison and award.** Per RFQ line, every quote side by side in base currency, the lowest marked; per supplier the total of what it offered. The buyer selects one quote line per RFQ line (lowest by default) and awards: one draft PO per selected supplier, in the quote's currency and prices; the RFQ closes. | Must |
| PRC-06 | **Tenders.** A tender at a branch: `open` (anyone with the public link may register, PRC-08) or `invited` (only bidders the buyer invites); lines; a submission deadline and an opening time (not before the deadline); technical criteria with weights (summing 100) and a maximum score each; the technical and financial weights (summing 100) and a technical pass mark; a committee of openers (at least two) and evaluators (tenant users holding `procurement.tender.open` / `.evaluate` at the branch). `draft` → `published` → `closed` (deadline passed, derived) → `opened` → `evaluated` → `awarded`, or `cancelled` at any point before award. Published tenders keep their lines, criteria and times. | Must |
| PRC-07 | **Supplier portal identity.** External suppliers never sign in as tenant users. Each bidder of a tender gets a **token link** (48 random characters, stored as sha256, valid until 90 days after the submission deadline, emailed through the Notifier as a system message, ADR 009). The link is exchanged for a **portal session**: a separate bearer token (48 characters, hashed, revocable; it expires 8 hours after its last use, and never more than 7 days after it started) bound to **one tenant, one tender and one bidder**. Portal routes take only that token (never a user token, and a user token is refused there); the tenant is found from the token hash through a security-definer function (ADR 002) and every query then runs under that tenant's row-level security and filters by the session's tender and bidder. A session ends at sign-out, on revocation (bidder removed, tender cancelled) or when idle. After the deadline the portal is read-only: the bidder still sees the tender, its receipt and, later, the outcome; nothing it sends changes anything. Portal times show in the tender's time zone (the branch's, else the company's). | Must |
| PRC-08 | **Open and invited bidders.** Invited: the buyer adds a supplier party (its email or a typed one) or an outsider by name, tax ID and email; the link is sent. Open: a public notice page (`public` token in the link) shows the tender's title, branch, deadline and lines; an outsider registers with company name, tax ID and email, and receives a token link by email (so the email is proven). One bidder per email per tender. Registration always answers 202 `{sent: true}`, whatever happened (a new bidder, a known email whose link is sent again, a closed tender, a conflict of interest), so the page reveals nothing about who bids; each request is audited with its outcome (`procurement.portal.registration`). The notice token is random (48 characters), stored as its sha256 and, encrypted, for showing the link to the buyer again. **Conflict of interest:** an email that belongs to a member of the tender's committee is never a bidder (refused when added by the buyer, `conflict_of_interest`; silently ignored at registration), and a user whose email is a bidder's is never put on the committee. Outside bidders become core parties only when awarded (PRC-11). | Must |
| PRC-09 | **Sealed bids.** A bidder submits a bid before the deadline: currency, a unit price per tender line (or no offer), a technical response, attachments. The bid's content is **encrypted at rest** with the tender's own key (a random 256-bit key per tender, itself encrypted with the application key; `Illuminate\Encryption\Encrypter`, AES-256-CBC with MAC); attachments are encrypted the same way before they reach the `media` disk (same disk, path layout and type whitelist as core file uploads, ADR 011). Before the opening nothing in the API, exports (EXP-01, EXP-02), the audit log or the approvals inbox shows a bid's content: buyers see only that a bid exists, who sent it, when, and its fingerprint (sha256 of the ciphertext, also shown to the bidder as a receipt). A bidder may replace or withdraw its bid until the deadline (each version audited, only the last kept); nothing changes after it. The bid currency must have a rate to the base currency at submission (`rate_unavailable`, 422), and the portal offers only the tenant's active currencies. The sealed payload names its tender, bidder and version; the opening checks them, so ciphertext moved between bids is caught. File names, types and sizes stay out of the audit log and appear in exports only once the tender is opened. Uploads are not scanned for malware (open question 5). | Must |
| PRC-10 | **Timed opening by a committee.** Bids open only at or after the opening time and only when **two different committee openers** have each confirmed the opening (`POST …/open`, each audited). The second confirmation decrypts every submitted bid inside one transaction: amounts, lines and technical responses are stored in plain columns, the tender is `opened`, and the opening is audited with both openers, the bid count and every fingerprint (`procurement.tender.opened`). A bid whose ciphertext no longer matches its fingerprint, fails its MAC, or names another tender, bidder or version is marked `tampered`. When the tender's key itself cannot be unwrapped (the application key that wrapped it is gone, ADR 019), nothing is opened: the confirmation fails with `seal_key_unavailable` (500) and the transaction rolls back, so no bid is marked tampered. | Must |
| PRC-11 | **Scored evaluation and award.** Evaluators score each opened bid on each technical criterion (0 to the criterion's maximum, with a comment); a bid's technical score is the weighted mean over **all** the committee's evaluators, out of 100: it exists only once every evaluator has scored every criterion, so every bid is averaged over the same evaluators, and the tender becomes `evaluated` only when every opened bid has its score. Bids under the pass mark are disqualified. The financial score is `lowest passing price ÷ bid price × 100` in base currency (prices converted at the company's rate at opening, CUR-04). Combined = technical × technical weight + financial × financial weight, ranked (integer arithmetic on basis points, half up). The award (`procurement.tender.award`) picks one passing bid (the top one by default; another needs a reason, audited): the tender is `awarded`, an outside bidder becomes a core supplier party, a draft PO is created from the bid, and the others are regretted. | Must |
| PRC-12 | **Award and regret letters.** Letters print through document templates (TPL-01): `procurement.award_letter` and `procurement.regret_letter` (A4, with the tender, the bidder, the awarded total for the winner), as PDF, and may be emailed to the bidder (TPL-04, `DocumentEmails`). | Must |
| PRC-13 | **Purchase orders, multi-currency.** A PO for one supplier at a branch with a delivery location: currency (any active currency), lines (item or description, unit, quantity, unit price, tax code, cost centre and project), expected date, terms. Line amounts as Accounting invoices compute them (round half up; tax from the core code and the rate in force on the order date, inclusive or exclusive per PO; a code whose rate is "Rate needed" blocks submission, `tax_rate_missing`; rates are never invented). Submitting numbers it, freezes the rate and base amounts (CUR-04) and starts the `procurement.po` flow (default WF-02: one approval by a Branch Manager at the branch, escalated to the Admins after two business days; APR-07). The flow may route on the PO's base total, supplier, branch and currency (WF-05). Approved POs print with the `procurement.po` template and can be emailed to the supplier. Statuses `draft`, `pending`, `approved`, `partially_received`, `received`, `closed`, `rejected`, `cancelled`. | Must |
| PRC-14 | **Goods received notes.** A GRN receives an approved PO at one location of its company: per PO line the quantity received and the quantity rejected for quality (with a reason), batch number and expiry, serials, and weight. A GRN is saved as a draft, then posted: numbered, valued at the PO's net unit price for the accepted quantity, converted to base at the company's rate on the receipt date (CUR-04), with any landed cost already allocated to it (PRC-17); the PO's received quantities and status follow. Receiving more than ordered (beyond the over-receipt allowance) is refused (`over_receipt`). Posted GRNs are immutable; a draft can be cancelled. Each posted line records whether it arrived on time (receipt date ≤ the PO's expected date). | Must |
| PRC-15 | **Supplier invoices with three-way match.** An invoice of the PO's supplier, in the PO's currency, names the PO and, per line, the PO line, quantity and unit price, tax code. Matching compares each line with the PO (price within the price tolerance) and with what was received and not yet invoiced (quantity within the quantity tolerance): each line is `matched`, `price_variance`, `qty_exceeds_received` or `not_received`, and the invoice `matched` or `exception`. Approving needs `procurement.invoice.approve`; approving an exception also needs `procurement.invoice.override` and a reason. Approval freezes the rate and base amounts and raises `SupplierInvoiceApproved` (Integrations). Statuses `draft`, `matched`, `exception`, `approved`, `cancelled`. | Must |
| PRC-16 | **Multi-currency.** Requisitions, quotes, bids, POs, invoices and landed cost charges may be in any active currency of the tenant; each stores the rate used (`numeric(18,8)`) and base amounts once it is submitted, posted or approved; comparisons and evaluations are in base currency. A missing rate refuses the step with `rate_unavailable`, naming the pair and date. | Must |
| PRC-17 | **Landed costs.** A landed cost document holds charges (freight, duty, insurance, clearing; description, optional supplier party, currency and amount) and the GRNs they apply to (draft or posted, of one company), with an allocation method: `value` (base value of accepted goods), `quantity` (accepted quantity in the item's base unit) or `weight` (the GRN line's weight; refused when a line has none, `weight_missing`). Posting converts each charge to base at the company's rate on the document date and splits the total over the receipt lines **in integer minor units by largest remainder** (`Money::allocate`): the parts always add up to the total exactly, a line with a zero basis gets nothing. A draft GRN takes its allocation into the cost it posts with; a posted GRN's allocation raises `LandedCostAllocated` (Integrations). Posted landed costs are immutable. | Must |
| PRC-18 | **Supplier performance.** Per supplier and period (company, date range): POs and receipts counted; **on time** (share of posted GRN lines received on or before the PO's expected date); **quality** (accepted ÷ received quantity); **price variance** (Σ (invoiced − ordered unit price) × invoiced quantity, in base currency, and as a percent of the ordered value); average lead time (PO approval to receipt, days). A list with export (EXP-01). | Must |
| PRC-19 | **Numbering** (NUM-01, yearly per branch): requisitions `PR-{BRANCH}-{YYYY}-{00001}`, RFQs `RFQ-{BRANCH}-{YYYY}-{00001}`, tenders `TND-{BRANCH}-{YYYY}-{00001}`, POs `PO-{BRANCH}-{YYYY}-{00001}`, GRNs `GRN-{BRANCH}-{YYYY}-{00001}`, invoices `PINV-{BRANCH}-{YYYY}-{00001}` (the supplier's own number is kept as its reference), landed costs `LC-{BRANCH}-{YYYY}-{00001}`. | Must |
| PRC-20 | **Custom fields** (CF-01 to CF-06): requisitions and POs carry custom fields (`custom` jsonb, GIN index), entities `procurement_requisition` and `procurement_po`, in their forms, details, flows' conditions (WF-04) and the PO template. | Must |
| PRC-21 | **Lists and exports** (EXP-01, LAY-04): requisitions, RFQs, tenders, POs, GRNs, invoices, landed costs and supplier performance, with search, filters (status, company, branch, location, supplier, dates), sort, saved views and export, scoped by place (RBAC-04). | Must |
| PRC-22 | **Scope and permissions.** Every endpoint checks `procurement.*` at the document's branch (location for GRNs), RBAC-04; ids of other tenants or out of scope answer 404; bodies naming another tenant's or company's records are refused (422). A landed cost takes only GRNs at locations where the user holds `procurement.receipt.view` or `.landed_cost.create` (`receipt_unknown` otherwise), and its detail shows only the GRNs and allocation lines the reader may see. Committee pickers read `GET procurement/branches/{branch}/committee-candidates?role=opener|evaluator` (needs `procurement.tender.create` at the branch), never the core user list. | Must |
| PRC-23 | **Portal security.** Portal routes are rate-limited per IP and per token (`procurement-portal`: 30 a minute per token and 120 a minute per IP; sign-in and registration 10 a minute per IP and 5 an hour per email); a session sees only its own tender and its own bid, never other bidders (not even their count) nor another tenant; uploads are limited to 10 files of 10 MB of the core types (the count is taken under the bidder's row lock); an upload not yet in a submitted bid can be removed. Every portal action (link sent, session started and ended, bid submitted, replaced, withdrawn, file uploaded, registration) is audited with the bidder and IP, without the bid's content. | Must |
| PRC-24 | **Audit** (AUD-01): every create, update, submit, decision, issue, award, posting, cancellation, opening confirmation, score, letter and export of every procurement document; sealed contents never enter the audit log before opening (PRC-09). | Must |
| PRC-25 | **Events** (after commit): `PurchaseOrderApproved`, `GoodsReceiptPosted`, `SupplierInvoiceApproved`, `LandedCostAllocated`, `TenderOpened`, `TenderAwarded`; and Inventory's `GoodsReceived` contract for stock lines (INV-15). | Must |

## Data model

Every table has `tenant_id` under forced RLS (TEN-01) and UUID v7 keys.
Users are referenced by `(tenant_id, id)`. Money `bigint` minor units next
to a currency; base amounts in `base_currency`; rates through the
`fxSnapshot('fx')` columns (`numeric(18,8)`). Documents are never deleted
(TEN-06): drafts end `cancelled`.

| Table | Holds |
| --- | --- |
| `procurement_settings` | Per company: `price_tolerance_percent`, `qty_tolerance_percent`, `over_receipt_percent` (numeric(7,4)) |
| `procurement_requisitions`, `…_lines` | Number, company, branch, location, department, requester, needed by, purpose, currency, estimated total, status, `custom`; lines with item, description, unit, quantity, estimated unit price, cost centre, project, needed by |
| `procurement_rfqs`, `…_lines`, `…_suppliers` | Number, company, branch, requisition, title, deadline, status; lines (item, description, unit, quantity, requisition line); invited supplier parties |
| `procurement_quotes`, `…_lines` | RFQ, supplier, reference, quote date, valid until, currency, fx, status (`received`, `awarded`, `not_awarded`); lines with RFQ line, unit price, base unit price, lead time days, selected |
| `procurement_tenders` | Number, company, branch, title, description, kind (`open`, `invited`), deadline, opening time, weights, pass mark, status, `seal_secret` (the tender key, encrypted with the app key; never exported), `public_token_hash` (open tenders), opened at, awarded bid |
| `procurement_tender_lines`, `…_criteria`, `…_members` | Lines; technical criteria (name, weight, max score); committee members (user, role `opener` or `evaluator`) |
| `procurement_tender_bidders` | Tender, party (null for an outsider until award), name, tax ID, email, `token_hash`, token expiry, source (`invited`, `registered`), invited by, last seen |
| `procurement_portal_sessions` | Bidder, tender, `token_hash`, expires, last used, revoked, IP and user agent of the start |
| `procurement_bids` | Tender, bidder, status (`submitted`, `withdrawn`, `opened`, `tampered`), version, submitted at, `fingerprint`, `sealed_payload` (ciphertext; never exported), and after opening only: currency, fx, total, base total, `lines` and technical response, scores, rank, outcome (`awarded`, `regretted`, `disqualified`) |
| `procurement_bid_files` | Bid, sealed path, name, mime, size (the file's bytes encrypted with the tender key) |
| `procurement_tender_openings` | Tender, opener, confirmed at (unique per tender and user) |
| `procurement_bid_scores` | Bid, criterion, evaluator, score, comment (unique per bid, criterion and evaluator) |
| `procurement_orders`, `…_lines` | Number, company, branch, delivery location, supplier, currency, fx, tax inclusive, order date, expected date, terms, status, totals and base totals, sources (requisition, quote, bid), requester, approved and sent times, `custom`; lines with item, description, unit, quantity, unit price, tax code and rate, net, tax, total, received, rejected and invoiced quantities, cost centre, project |
| `procurement_receipts`, `…_lines` | Number, company, branch, location, PO, supplier, received at, delivery note, status, fx, base currency; lines with PO line, item, unit, received, rejected and accepted quantities, reject reason, batch, expiry, serials, weight, value, base value, landed base value, on time |
| `procurement_invoices`, `…_lines` | Number, supplier reference, company, branch, supplier, PO, invoice and due dates, currency, fx, status, totals and base totals, override reason, approver; lines with PO line, quantity, unit price, tax, net, total, match status, price and quantity variance |
| `procurement_landed_costs`, `…_charges`, `…_receipts`, `…_allocations` | Number, company, branch, date, method, status, base total; charges (description, supplier, currency, amount, fx, base amount); the GRNs; allocations per GRN line (basis, base amount) |

## Events

**Raised** (`Modules\Procurement\Events`, `ShouldDispatchAfterCommit`, ids
and values only):

| Event | When | Carries |
| --- | --- | --- |
| `PurchaseOrderApproved` | A PO's flow approved it | tenant, PO id, company, branch, supplier, currency, total, base total |
| `GoodsReceiptPosted` | A GRN posted | the Accounting payload `procurement.goods_receipt` below |
| `SupplierInvoiceApproved` | An invoice approved | the Accounting payload `procurement.supplier_invoice` below |
| `LandedCostAllocated` | A landed cost posted | the payload `procurement.landed_cost` below |
| `TenderOpened`, `TenderAwarded` | Opening, award | tender id, company, bids count; awarded bid and party |

And Inventory's **`Modules\Inventory\Contracts\GoodsReceived`** (INV-15)
when a GRN posts with stock items: `receiptId` = GRN id, the location, the
receipt time, the GRN number, and per accepted stock line `{line_id,
item_id, uom_id, qty (accepted), cost_minor (goods base value + landed base
value), currency (the base currency), batch_number, expires_on, serials}`.
Costs are already in base currency, so Inventory does not convert them
again. Raised while Inventory or Stores is active for the tenant (each
listener decides what it receives: Inventory when it is active, Stores'
own ledger at a store when Inventory is off; decision 17). Each receipt
line records whether its stock was tracked (`stock_tracked`: the event
was raised for it with Inventory active), which is what its invoice
later clears GRNI over (decision 16).

**Accounting payloads** (`SourceDocument` of ACC-07: `company_id`,
`source_type`, `source_id`, `date`, `currency` = the company's base
currency, `amounts` as minor digit strings, `fx` the document's snapshot,
`dimensions`, `reference`). Amounts are base currency so posting rules
need no conversion:

| Source type | Amount fields | Dimensions | Journal it stands for |
| --- | --- | --- | --- |
| `procurement.goods_receipt` | `stock_value` (stock lines, goods + landed), `expense` (non-stock and service lines), `grni` (goods value received, = stock goods + expense), `landed_cost` (landed part included above) | branch, party (supplier), cost centre and project when every line shares one | Dr stock / Dr expense — Cr GRNI, Cr landed cost clearing |
| `procurement.landed_cost` | `stock_value` (allocated to posted stock lines), `expense` (to posted other lines), `landed_cost` (= their sum) | branch | Dr stock / Dr expense — Cr landed cost clearing |
| `procurement.supplier_invoice` | `net` (invoice net, base at the invoice rate), `tax`, `total` (= the payable), `grni` (the received value the invoice clears, at the GRN rates), `price_variance` (= net − grni) | party (supplier), branch, cost centre, project | Dr GRNI, Dr price variance, Dr input tax — Cr AP |

`SupplierInvoiceApproved` also carries the AP document an Accounting
listener needs (decision 3): `{invoice_id, number, supplier_reference,
party_id, company_id, branch_id, invoice_date, due_date, currency, fx,
tax_inclusive, lines: [{description, item_id, qty, unit_price_minor,
net_minor, tax_code_id, tax_rate, tax_minor, total_minor, base_net_minor,
base_grni_minor, cost_centre_id, project_id, kind: stock|expense}],
totals {net, tax, total} and base totals}`, all money as minor digit
strings in the invoice currency unless named `base_`.

`LandedCostAllocated` carries `{landed_cost_id, number, company_id,
branch_id, date, base_currency, charges: [{id, line_no, description,
party_id, bill_reference, currency, amount_minor, fx, base_amount_minor}],
allocations: [{receipt_id, receipt_line_id, item_id, location_id, stock:
bool, after_receipt: bool, tracked: bool, base_amount_minor}]}`; its
`stock_value` covers only tracked stock lines, `expense` the rest
(decision 19). It is raised for every posted landed cost (its
charges are what the charges' suppliers bill, Dr landed cost clearing / Cr
AP); its `amounts` cover only the allocations to GRNs already posted
(`after_receipt`): what went to draft GRNs reaches Accounting and Inventory
with those GRNs' own events.

**Consumed:** `WorkflowCompleted`, `WorkflowCancelled` (core) settle
requisitions and POs.

## Permissions

| Permission | What it allows |
| --- | --- |
| `procurement.requisition.view`, `.create`, `.approve`, `.cancel` | Read; draft, edit and submit; act on the requisition flow (WF-08); cancel |
| `procurement.rfq.view`, `.create`, `.cancel` | Read; draft, issue, enter quotes, award, close; cancel |
| `procurement.tender.view`, `.create`, `.open`, `.evaluate`, `.award`, `.cancel` | Read (sealed content never); draft, publish, invite bidders; confirm an opening (committee openers); score (committee evaluators); award and letters; cancel |
| `procurement.po.view`, `.create`, `.approve`, `.cancel`, `.print` | Read; draft, edit, submit, mark sent; act on the PO flow; cancel; print and email |
| `procurement.receipt.view`, `.create`, `.cancel` | Read; draft and post GRNs; cancel a draft |
| `procurement.invoice.view`, `.create`, `.approve`, `.override`, `.cancel` | Read; enter and match; approve a matched invoice; approve an exception (with `approve`); cancel |
| `procurement.landed_cost.view`, `.create`, `.cancel` | Read; draft and post; cancel a draft |
| `procurement.performance.view` | Supplier performance |
| `procurement.settings.edit` | A company's tolerances |
| `procurement.value.view` | Prices, values and landed costs (RBAC-05, decision 27): without it, money fields are null in resources, exports and prints, and a PO cannot be printed |

Role templates (RBAC-03): **Owner** everything; **Admin**
`procurement.settings.edit` and the views; **Branch Manager** the views,
`procurement.requisition.*`, `procurement.po.approve` (the default
approver) and `procurement.tender.open` and `.evaluate` (committees); **Procurement Officer** `procurement.*` except the `approve`,
`override`, `award` and `settings.edit` permissions; **Storekeeper** the
requisition and PO views, `procurement.requisition.create` and
`procurement.receipt.*`, never `procurement.value.view` (quantities
only); **Accountant** the views (with `procurement.value.view`),
`procurement.invoice.*` and `procurement.landed_cost.*`; **Approver**
`*.approve`, the views of what it approves (requisitions, POs, invoices)
and `procurement.value.view`; **Read-only Auditor** the views. Owner,
Admin, Branch Manager, Procurement Officer and Accountant hold
`procurement.value.view` through their `procurement.*` or
`procurement.*.view` grants (32 Procurement permissions in all; the
Storekeeper holds 6, the Approver 7). The templates' old `purchasing.*` placeholders are replaced.

## API

Prefix `/api/v1`. Back office: people's tokens, every route behind
`module:procurement` (403 `module_inactive`). Lists follow ADR 008
(`?search`, `?sort`, `?per_page`, `?page`, `?format=csv|xlsx|pdf`,
`?columns[]`, `?company`, `?branch`, `?location`, `?status`, `?party`,
`?from`, `?to`). Money in bodies is minor units as digit strings with a
currency (`unit_price_minor`, `amount_minor`); quantities decimal strings.

| Route | Permission | Notes |
| --- | --- | --- |
| `GET/PUT procurement/companies/{company}/settings` | `po.view`; `settings.edit` | PRC-01 |
| `GET/POST procurement/requisitions`, `GET/PATCH …/{id}`, `POST …/{id}/submit\|cancel` | requisition.* | PRC-02 |
| `GET/POST procurement/rfqs`, `GET/PATCH …/{id}`, `POST …/{id}/issue\|cancel\|award` (`{selections?: {rfq line id: quote line id}}`), `GET …/{id}/comparison`, `POST procurement/quotes` (`{rfq_id, party_id, …}`) | rfq.* | PRC-03 to PRC-05 |
| `GET/POST procurement/tenders`, `GET/PATCH …/{id}`, `POST …/{id}/publish\|cancel\|open`, `POST …/{id}/bidders`, `POST procurement/tender-bidders/{id}/resend\|remove` | tender.create, .open, .cancel | PRC-06 to PRC-10 |
| `PUT procurement/bids/{id}/scores`, `POST procurement/bids/{id}/award` (`{reason?}`) | tender.evaluate (committee evaluators); tender.award | PRC-11 |
| `GET procurement/bids/{id}/files/{file}`, `GET procurement/bids/{id}/letter`, `POST procurement/bids/{id}/letter/email` | tender.view after opening; tender.award | PRC-09, PRC-12 |
| `GET/POST procurement/orders`, `GET/PATCH …/{id}`, `POST …/{id}/submit\|cancel\|send`, `GET …/{id}/pdf` | po.* | PRC-13 |
| `GET/POST procurement/receipts`, `GET/PATCH …/{id}`, `POST …/{id}/post\|cancel` | receipt.* at the location | PRC-14 |
| `GET/POST procurement/invoices`, `GET/PATCH …/{id}`, `POST …/{id}/approve\|cancel` | invoice.* | PRC-15 |
| `GET/POST procurement/landed-costs`, `GET/PATCH …/{id}`, `POST …/{id}/post\|cancel` | landed_cost.* | PRC-17 |
| `GET procurement/branches/{branch}/committee-candidates` | tender.create at the branch | PRC-06; `?role=opener\|evaluator` |
| `GET procurement/supplier-performance` | performance.view | PRC-18; `?format=csv` |

**Supplier portal** (no user token; `Authorization: Bearer <portal
session>`; rate-limited, PRC-23):

| Route | Notes |
| --- | --- |
| `POST supplier-portal/sessions` | `{token}` (the link) → `{session_token, expires_at}` |
| `DELETE supplier-portal/sessions/current` | Sign out |
| `GET supplier-portal/tender` | The session's tender (title, branch, lines, criteria, times, currency), the bidder, its own bid's receipt (status, version, fingerprint, submitted at; never its content: nothing decrypts a sealed bid before the opening, not even for its bidder) and its uploaded files |
| `PUT supplier-portal/bid` | `{currency, lines: [{tender_line_id, unit_price_minor}], technical_response, file_ids?}` before the deadline; replaces the previous version |
| `POST supplier-portal/bid/files` | Upload one file (sealed) |
| `DELETE supplier-portal/bid/files/{file}` | Remove an upload not yet in a submitted bid |
| `POST supplier-portal/bid/withdraw` | Before the deadline |
| `GET supplier-portal/notices/{public token}` | An open tender's notice |
| `POST supplier-portal/notices/{public token}/register` | `{name, tax_id?, email}` → emails a token link |

Errors: `document_not_draft`, `document_not_open`, `party_not_supplier`,
`currency_inactive`, `rate_unavailable`, `tax_rate_missing`,
`over_receipt`, `po_not_approved`, `line_not_on_po`, `weight_missing`,
`tender_not_published`, `deadline_passed`, `opening_not_due`,
`opener_not_member`, `already_confirmed`, `bid_sealed`, `bid_tampered`,
`not_evaluated`, `bid_not_passing`, `portal_session_invalid`,
`portal_link_invalid`, `already_registered`, `conflict_of_interest`,
`seal_key_unavailable`, `too_many_files`.

## Screens (web)

Under **Procurement** in the sidebar: Requisitions (list, form, detail
with flow, create RFQ, tender or PO), RFQs (list, form, detail with
invited suppliers, quote entry and the comparison grid with award),
Tenders (list, form, detail with bidders, the sealed bid list,
committee opening with each opener's confirmation, the evaluation score
sheet and ranking, award, letters), Purchase orders (list, form, detail,
PDF, send), Goods received (list, form from a PO, detail, post),
Supplier invoices (list, form from a PO, detail with the match view:
ordered, received, invoiced, price and quantity per line with each
line's result), Landed costs (list, form, detail with the allocation
table), Supplier performance, and the company settings. The **supplier
portal** is a separate minimal layout (no sidebar, the tenant's theme,
tokens only): sign-in from the link, the tender, the bid form with
attachments, the receipt with the fingerprint, withdraw, sign out; and the
open notice with registration. Design-system components and tokens only,
en/fr.

## Integrations

- **Procurement → Inventory** (both active): a posted GRN raises
  `GoodsReceived` for its accepted stock lines at base cost including
  landed costs allocated before posting. **Landed costs after receipt**:
  `LandedCostAllocated` carries each stock line's extra base cost
  (item, location, receipt line). Inventory consumes it as a value-only
  `cost_adjustment` movement of zero quantity on the receipt's cost layer
  or the weighted average (INV-25, approved by the owner on 2026-10-14;
  Inventory's `ApplyLandedCost`, once per allocation line).
- **Procurement → Accounting** (both active): `GoodsReceiptPosted`,
  `LandedCostAllocated` and `SupplierInvoiceApproved` with the payloads
  above. Accounting posts the invoice (ACC-32: an Accounting supplier
  invoice, Dr GRNI and the price variance, purchases and input tax, Cr
  the payable); the GRN posts once, from Inventory's `StockMoved`
  (`inventory.receipt`), so `GoodsReceiptPosted` and the GRN side of
  `LandedCostAllocated` post nothing in Accounting.
- **Alone**: without Inventory, GRNs still post (no stock moves); without
  Accounting, invoices still match and approve (no journal). Nothing in
  Procurement checks the other modules except to avoid raising events
  nobody hears.
- **Core**: parties (MD-01), items and units (MD-02), tax codes and rates
  (MD-03), dimensions (MD-05), currencies, rates and `Converter`,
  `BaseCurrencyLock` (CUR-), workflow and approvals (WF-, APR-),
  numbering (NUM-01), templates and document emails (TPL-), custom fields
  (CF-), list views and exports (LAY-04, EXP-01), notifications (NOT-02),
  audit (AUD-).

## Decisions taken while building (no owner input asked)

1. **Registry module `procurement`**, sold under the catalogue key
   `procurement` (Phase 6 already sells it). Permissions follow RBAC-01's
   own example (`procurement.po.approve`); the role templates' old
   `purchasing.*` placeholders are replaced.
2. **Portal identity: token links, no supplier accounts.** A link per
   bidder per tender, exchanged for a short session bound to that tender.
   A supplier account across tenders (password, 2FA) would make an
   identity that spans tenants; it is left for later (open question 2).
3. **Accounting AP owns the payable.** When Accounting is active, its
   listener on `SupplierInvoiceApproved` should create and post an
   Accounting supplier invoice (ACC-09) from the payload's document
   (lines debit GRNI and the price variance, tax to input tax, the
   payable credited with the party), so payments, ageing and statements
   stay in Accounting; Procurement keeps its invoice for matching and
   performance. The `amounts` (`net`, `tax`, `total`, `grni`,
   `price_variance`) allow a posting rule instead. Procurement never
   writes Accounting tables. Built by Accounting as ACC-32 (accounting.md).
4. **Receipts are valued at the receipt date's rate** (stock and GRNI in
   base currency); the invoice at its own rate; the difference is the
   invoice's `price_variance` (it includes exchange differences).
5. **One stock receipt journal.** Accounting posts the receipt from
   `GoodsReceiptPosted` (it knows stock and expense lines); Inventory's
   `StockMoved` movements with source `procurement.goods_receipt` must not
   post a second receipt journal (for the Accounting integrations work).
6. **Quotes are entered by the buyer**; the portal serves tenders.
7. **Bids are encrypted with a per-tender key wrapped by the application
   key.** The protection is the code path (nothing decrypts before a
   two-opener opening) and the database (ciphertext only); someone holding
   both the database and the application key could decrypt early. Keys
   that only the committee holds (threshold encryption) are left for
   later (open question 3). What the application key must keep to open
   old tenders, and the re-wrap command, are in ADR 019.
8. **The financial score uses the lowest passing price** (the common public
   procurement formula); prices with no offer on a line count as no offer
   for the whole bid (incomplete bids are scored on the lines they priced
   only when the tender allows partial bids, which this stage does not).
9. **No importer in this stage**: suppliers and items come through the
   core importers; procurement documents have none (IMP- is about master
   data and opening balances).
10. **Over-receipt is refused**, not flagged: a GRN is typed in the back
    office, unlike a till sale.
11. **Nothing decrypts a sealed bid before the opening**, not even for its
    bidder: the portal shows the bidder its receipt (version, time,
    fingerprint), and a change is a new full submission.
12. **`closed` is derived**: a published tender past its deadline shows
    as closed until it is opened (no stored status, so no scheduler).
13. **Committee members** must hold `procurement.tender.open` (openers)
    or `.evaluate` (evaluators) at the tender's branch when chosen; the
    opening needs two different openers, whatever the number chosen.
14. **Supplier performance** is a computed report; its export is CSV
    (audited, rate-limited like list exports), not the list export
    framework, which exports stored rows.
15. **Branch managers sit on committees** by default (open and evaluate),
    so a tenant has two openers without extra roles; buyers (Procurement
    Officer) run tenders but never award them.

### Stage 7B closing review (decisions, owner may change)

16. **GRNI follows what was tracked at receipt, not the modules at
    invoice time.** Each receipt line records `stock_tracked` (a stock
    line whose `GoodsReceived` was raised with Inventory active, so a
    receipt journal credited GRNI). An invoice line clears GRNI only over
    the tracked receipts of its PO line and keeps its tracked share of the
    base net (`base_stock_net_minor`, net × tracked qty ÷ received qty);
    the rest of its net is purchases (`expense`). Switching Inventory off
    or on between a GRN and its invoice leaves GRNI at zero.
17. **A GRN reaches Stores when Inventory is off.** `GoodsReceived` is
    raised whenever Inventory or Stores is active; Inventory's and
    Stores' own listeners decide what to receive.
18. **No invoice before receipt for stock.** The `not_received` override
    is refused for stock lines (422 `stock_line_not_received`); service
    lines may still be approved in advance with the override. GRNI is
    cleared cumulatively per PO line: round(V × min(invoiced so far, R) ÷
    R) less what approved invoices already cleared (V the tracked receipts'
    base value, R the accepted quantity), so the invoice that reaches the
    received quantity clears the exact remainder, whatever the rates of
    the receipts.
19. **Landed costs clear through their bills.** A landed cost document's
    charges name the charge supplier and the supplier's bill reference
    (both required to post: `charge_supplier_required`,
    `charge_bill_required`). `GoodsReceived` carries each line's
    `landed_minor` and Inventory keeps it on the receipt movement, so the
    receipt journal credits GRNI with the goods and the landed cost
    clearing account with the charges, whether they were allocated before
    or after receipt. With Accounting active, its listener posts each
    supplier's bill (charges grouped by supplier, bill reference and
    currency) as a posted Accounting supplier invoice, Dr landed cost
    clearing / Cr AP with the party, and the share of services, expense
    lines and stock Inventory did not track (Inventory off) Dr purchases /
    Cr clearing: from `LandedCostAllocated` after receipt
    (`amounts.expense`, each allocation's `tracked`) and from
    `GoodsReceiptPosted` before (`amounts.untracked_landed`). The clearing
    account nets to zero once the bills post.
20. **Lines Inventory or Stores cannot receive are visible.** A line they
    skip (an unknown or non-stock item, a unit the item does not have, no
    quantity) or a serial already in stock comes back as Inventory's
    `GoodsReceiptExceptions` contract event; Procurement keeps each module
    and code on the GRN line (`receive_exceptions`) and the GRN view flags
    the lines, so someone adjusts the stock by hand.
21. **The portal and billing (owner decision 2026-10-14).** A read-only
    (overdue) business keeps its supplier portal open. A suspended or
    cancelled business shows "Tender unavailable" (en/fr): the tender view
    says `unavailable`, and bids, withdrawals and file uploads or removals
    answer 409 `tender_unavailable`.
22. **Bidder confidentiality (owner decision 2026-10-14).** Before the
    opening, the tender resource shows staff only the number of bids and
    the invited suppliers' names: no emails, tax IDs or `last_seen_at`, no
    sealed bid list, and open-notice registrants not at all. They appear
    once the tender is opened. The audit log never holds a bidder's name,
    email or tax ID (`Bidder::$auditHidden`; a registration is audited with
    a keyed hash of the email), nor a bid file's name, type, size or path
    (`BidFile::$auditHidden`); tender exports and notifications carry no
    bidder detail. Bids list in the order submitted, then by id.
23. **Separation of duties on supplier invoices.** Whoever entered an
    invoice never approves it, with or without the override (403
    `invoice_approver_is_creator`); the resource's `can.approve` and
    `can.override` say so.
24. **Requisitions of another branch (RBAC-04).** A PO, RFQ or tender
    takes on only an approved requisition of its own branch that the user
    sees there (`procurement.requisition.view`); an unknown requisition,
    one of another branch and one the user cannot see all answer the same
    `requisition_not_approved`.
25. **The portal's origin (owner decision 2026-10-14).** The portal stays on
    the back office's origin; its pages send no referrer and carry a strict
    Content-Security-Policy, its API responses `Referrer-Policy:
    no-referrer`. What mitigates the shared origin is in ADR 019.
26. **Supplier performance CSV** passes every cell, the header included,
    through `SpreadsheetCell::safe()` (no formula) and is written without
    an escape character, like list exports (EXP-01).
27. **Storekeepers see quantities only (RBAC-05).** A new permission
    `procurement.value.view` guards prices, values and landed costs: the
    PO, requisition, GRN, invoice and landed cost resources return money
    fields as null without it at the document's branch or location (lists,
    details and exports, which are built from the same resources), and a
    PO cannot be printed without it. Granted to Owner, Admin, Branch
    Manager, Procurement Officer, Accountant, Approver and the Read-only
    Auditor (their view grants); not to the Storekeeper.

### Stage 7D (Fixed assets)

28. **Payloads for Fixed assets (additive).** `GoodsReceiptPosted` also carries `lines` (the accepted lines: line and PO line ids, item, description, quantity, base value, landed cost, serials, location) and `SupplierInvoiceApproved`'s document carries each line's `line_id` and `order_line_id` and the PO's delivery `location_id`, so Fixed assets turns items flagged as assets into draft assets (fixed-assets.md FA-21). Nothing in Procurement reads Fixed assets.

## Open questions

1. ~~May Inventory's ledger take value-only cost adjustments?~~ Answered
   yes by the owner on 2026-10-14 (INV-25).
2. Should suppliers get accounts across tenders (and tenants)?
3. Should bid keys be split among the committee (threshold encryption),
   so not even the platform can open bids early?
4. Should invoices of non-PO purchases (rent, utilities) live in
   Procurement or stay in Accounting (ACC-09)? Today: Accounting.
5. Uploads (bid files here, like every core file upload) are not scanned
   for malware. A platform-wide scanner (for example ClamAV on the queue
   before a file becomes downloadable) is a gap to decide.

## Status (stage 7B)

| ID | Status |
| --- | --- |
| PRC-01 to PRC-25 | Built and tested (modules/Procurement/tests, the isolation suite) |
| PRC-17 (Inventory side) | Built: Inventory's `ApplyLandedCost` listener posts a `cost_adjustment` movement per allocated GRN line (INV-25) |
| PRC-25 (Accounting side) | Built: Accounting's `SupplierInvoices` posts approved invoices as Accounting supplier invoices (ACC-32, ProcurementPostingTest) |

## Deferred

- Partial bids, lots awarded to several bidders, reverse auctions.
- Supplier returns (debit notes) and credit notes against invoices.
- Blanket orders and contracts, approved supplier lists and supplier
  onboarding questionnaires.
- RFQ responses through the portal.
- Dashboard widgets (open POs, overdue deliveries).
