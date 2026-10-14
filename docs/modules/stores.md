# Stores module spec (phase 7B)

Source: concept note section 5 (Stores row: internal store requisitions,
issues to departments, returns to store, store ledgers, consumption
reports; integrates with Inventory and Procurement) and the integration
rule; platform core spec (TEN-01, TEN-04, TEN-06, RBAC-01 to RBAC-09,
MD-02, MD-05, CUR-04, WF-01, WF-02, WF-10, APR-01, APR-02, APR-07, NUM-01,
TPL-01, TPL-04, LAY-04, AUD-01, EXP-01); ADR 002 (RLS), ADR 003 (money),
ADR 008 (lists); docs/modules/inventory.md (the stock ledger Stores defers
to). When this file and the core spec disagree, the core spec wins.

## Scope

Stores is a module (`api/modules/Stores`, permissions `stores.*`, catalogue
key `stores`), sold and switched on per tenant (RBAC-08). It runs the
internal side of stock: a department asks a store for goods (a
requisition), the request is approved, the store issues the goods (all at
once or in parts), the department acknowledges what it received, unused
goods come back (a return), and the business sees what each department,
cost centre and item consumed.

- **Departments and cost centres** are core dimensions (MD-05) of the
  store's company. A requisition names one department and, optionally, one
  cost centre.
- **Stores** are core locations (TEN-04) of type `store` or `warehouse`,
  the same locations Inventory keeps stock at. A requisition is made to one
  store; everything about it is scoped by that location (RBAC-04).
- **Items** are core items of type `stock`, shared or of the store's
  company (TEN-08). Quantities are typed in any unit of the item (core item
  units, MD-02) and kept as exact decimals, `numeric(20,6)`, with the
  quantity in the item's base unit beside them. Quantities travel as
  decimal strings.
- **Two ledgers, one at a time.** Stores works with only the core. Then it
  keeps the store's quantities itself (its own ledger). When the tenant
  also has Inventory active, Inventory holds the quantities and values:
  Stores raises events, Inventory moves and values the stock, and Stores
  records what it was told. Stores never reads or writes Inventory's tables,
  and Inventory never reads Stores' tables.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| STR-01 | **Stand-alone module.** Stores registers through the core seams (module registry, permission catalogue, role templates, document types, number formats, document templates, list views) and works with only the core. Every route is behind `module:stores` (403 `module_inactive`). | Must |
| STR-02 | **Requisitions.** A requisition is made to a store (a location of type `store` or `warehouse`, not archived; `not_a_store` otherwise) for a department of the store's company (`department_other_company`), with an optional cost centre of that company, an optional date needed and note, and 1 to 200 lines (stock item shared or of the company, a unit of the item, a positive quantity within the unit's precision, a note). It is numbered (STR-15) and starts its flow (STR-03) in the same transaction, `pending`. | Must |
| STR-03 | **Approval through the workflow engine.** A requisition is the workflow document `stores.requisition` (WF-01). Its default flow (WF-02) is one approval by the department head (APR-02 `department_head`, the department's owner), escalated to the Branch Managers at the store after two business days; a department without a head goes to them at once (APR-07 fallback). The requester never approves (APR-07). Approved: `approved`; rejected: `rejected`. Conditions may route on the store, branch, company, department, cost centre and number of lines. A tenant may publish its own flow, without an approval included. | Must |
| STR-04 | **Issues, full or partial.** A storekeeper issues against an approved requisition: each issue line names a requisition line and a positive quantity in that line's unit; what is issued over all issues never exceeds what was approved (`over_issue`). The requisition moves to `partially_issued`, then `issued` when every line is fully issued. A requisition with quantities still open can be closed (`closed`, with a reason); a pending or approved one with nothing issued can be cancelled (its flow cancelled). Issues are numbered (STR-15). | Must |
| STR-05 | **Department acknowledgement.** The department acknowledges an issue (`issued` → `acknowledged`), with an optional note; who and when are kept. The person who issued it cannot acknowledge it (`acknowledge_self`). Issues waiting for acknowledgement are listed (`?status=issued`). | Must |
| STR-06 | **Returns to store.** Goods come back against an issue's lines: a positive quantity per line, never more than issued less what was already returned (`over_return`), with a reason (`unused`, `excess`, `wrong_item`, `damaged`, `other`) and an optional note. Numbered (STR-15). A return follows its issue's ledger (STR-07, STR-08): an issue moved by Inventory comes back through Inventory at the cost it went out at. | Must |
| STR-07 | **Stores' own ledger (Inventory off).** `stores_movements` is append-only (a trigger refuses deletes and every update except recording a value once). Each movement is signed, per store and item, in the base unit, with the balance after it. Balances move under a lock per store and item. Issues beyond what the store holds are refused (`insufficient_stock`, naming the item). Stock enters through stock entries (`opening`, `adjustment` up or down, with a reason; `stores.stock.adjust`) and through goods received by another module (Inventory's `GoodsReceived` contract at a store location) while Inventory is off. After an Inventory period (Inventory switched off again), a store's own balances are stale: they count only movements since its last Inventory-ledger movement, and own-ledger issues and adjustments there are refused (`opening_entry_required`) until an opening stock entry is made for that store. | Must |
| STR-08 | **Inventory integration (Inventory on).** When the tenant has Inventory active at the moment of an issue or return, Stores raises `StoreIssued` / `StoreReturned` after commit, and Inventory's listener posts `store_issue` / `store_return` movements at the store (never refused: a shortfall is flagged `negative_stock`, as POS sales are; batch items are taken earliest expiry first). A return comes back at the cost its issue went out at. An event delivered twice moves stock once (the ledger is idempotent per source line). Stores' own movement rows are still written (the record of its documents) but carry no balance: the quantity on hand is Inventory's. Stock entries are refused (`inventory_active`). The ledger is decided per document when it is saved (Inventory's ledger only while Inventory is active) and kept on it: a document whose ledger is Inventory's posts there whatever the module's state when Inventory's job runs, and a return always goes through its issue's ledger (an Inventory-ledger issue comes back through Inventory even after Inventory is switched off; an own-ledger issue comes back into the own ledger even after it is switched on). Issues and returns take each line's own unit factor (`qty_base / qty` of the requisition line or issue line, fixed when it was saved), never the item's current factor. | Must |
| STR-09 | **Values.** With Inventory on, Inventory's `StockMoved` for a `stores.issue` or `stores.return` source carries the value of each movement (company base currency, ADR 003); Stores records it on its document lines and movements once, then raises `StoreConsumptionValued` (STR-16). Recording is a queued listener, retried; when Inventory's job is delivered again for a document it already posted, it raises `StockMoved` again, so a value missed once is recorded later (the document's `valued_at` keeps it once). With Inventory off, Stores has no costs: consumption is in quantities only and values show as "not valued". | Must |
| STR-10 | **Store ledger.** Per store and item: every Stores movement (kind, document, date, signed quantity, balance after it in the own ledger, value when known), with filters (store, item, kind, dates), search, sort and export (EXP-01). With Inventory on, the screen says the full stock ledger is Inventory's and links to it. | Must |
| STR-11 | **Stock on hand (own ledger).** Quantity per store and item from the own ledger, with filters and export. | Must |
| STR-12 | **Consumption reports.** Net consumption (issued − returned) over a period, grouped by department, cost centre, item or month (`?group=department\|cost_centre\|item\|period`), one row per group and item, with issued, returned and net quantity in the item's base unit and, when valued and visible, issued, returned and net value; totals per currency. Dates are issue and return dates in the company's time zone. Filters: store, department, cost centre, item, `from`, `to`. Export (EXP-01). | Must |
| STR-13 | **Scope.** Every list, document and action is scoped by the store location (RBAC-04): a document out of the reader's places is not found. Values are shown only to holders of `stores.value.view` at the store (RBAC-05), never exported to others. | Must |
| STR-14 | **Printed documents** (TPL-01, TPL-04). An issue prints as `stores.delivery_note` (what was handed over: items, quantities, units, the department and the signature block, no values) and as `stores.issue_voucher` (the store's record: requisition, department, cost centre, quantities and, when valued and visible, unit cost and value). HTML for printing or PDF. Black on white in every theme. | Must |
| STR-15 | **Numbering** (NUM-01): requisitions `REQ-{BRANCH}-{YYYY}-{00001}`, issues `ISS-{BRANCH}-{YYYY}-{00001}`, returns `SRN-{BRANCH}-{YYYY}-{00001}`, yearly. | Must |
| STR-16 | **Events raised** (after commit): `StoreIssued`, `StoreReturned` (always, saying which ledger moved), `StoreConsumptionValued` (once per issue or return, when Inventory valued it) carrying the company, store, department, cost centre, date, currency and line values Accounting needs to post department expense against stock. | Must |
| STR-17 | **Audit** (AUD-01): every requisition, issue and return created, decided, acknowledged, closed or cancelled; every stock entry. The movement ledger itself is the record of every quantity. | Must |
| STR-18 | **Back-office lists** (EXP-01, LAY-04): requisitions, issues, returns, ledger, stock and consumption with search, filters, sort, saved views and export. | Must |

## Data model

Every table has `tenant_id` under forced RLS (TEN-01) and UUID v7 keys.
Quantities `numeric(20,6)`; values `bigint` minor units with a
`currency char(3)` (the company's base currency, from Inventory).

| Table | Holds |
| --- | --- |
| `stores_requisitions` | Number, company, branch, store `location_id`, `department_id`, `cost_centre_id`, `needed_by`, note, status (`pending`, `approved`, `partially_issued`, `issued`, `closed`, `rejected`, `cancelled`), requester, decider and time, closer, time and reason, canceller, time and reason |
| `stores_requisition_lines` | Line no, item, unit, `qty`, `qty_base`, `qty_issued` (in the line's unit), note |
| `stores_issues` | Number, requisition, company, branch, location, department, cost centre, `ledger` (`stores`, `inventory`), status (`issued`, `acknowledged`), note, issuer and `issued_at`, acknowledger, time and note, `valued_at`, `currency` |
| `stores_issue_lines` | Requisition line, item, unit, `qty`, `qty_base`, `qty_returned_base`, `value_minor` (null until valued) |
| `stores_returns` | Number, issue, company, branch, location, department, cost centre, `ledger`, reason, note, who and `returned_at`, `valued_at`, `currency` |
| `stores_return_lines` | Issue line, item, unit, `qty`, `qty_base`, `value_minor` |
| `stores_movements` | Append-only: company, location, item, `kind` (`opening`, `receipt`, `adjustment_in`, `adjustment_out`, `issue`, `return`), `ledger`, signed `qty` (base unit), entered unit and quantity, `balance_after` (own ledger only), `value_minor` and `currency` (recorded once), source type, id and line, department, cost centre, reason, `occurred_at`, who; unique per source line and kind |

## Events

**Raised** (`Modules\Stores\Events`, dispatched after commit, ids and
values only):

| Event | When | Carries |
| --- | --- | --- |
| `StoreIssued` | An issue is saved | tenant, issue id and number, company, branch, store location, department, cost centre, `ledger`, `issued_at`, user, lines `[{line_id, item_id, uom_id, qty, qty_base}]` |
| `StoreReturned` | A return is saved | tenant, return id and number, issue id, company, branch, store location, department, cost centre, `ledger`, `returned_at`, user, lines `[{line_id, issue_line_id, item_id, uom_id, qty, qty_base}]` |
| `StoreConsumptionValued` | Inventory valued an issue or return | tenant, `direction` (`issue`, `return`), document id and number, company, branch, store location, department, cost centre, date, currency, total `value_minor`, lines `[{line_id, item_id, qty_base, value_minor, cost_variance_minor}]` |

**For Accounting (stage 7B onwards).** An issue is department expense
against stock: debit the department's consumption (by department and cost
centre), credit stock at the store, at Inventory's value. A return
reverses it. Each line carries Inventory's `cost_variance_minor` (what a
later receipt corrects of a shortfall's cost). **Decision:** for
`stores.*` sources, Accounting posts from `StoreConsumptionValued` and
skips Inventory's `StockMoved`, which describes the same movement (the
`stores.consumption` source and its default rule, docs/modules/accounting.md
ACC-31).
With Inventory off there is no value and nothing to post.

**Consumed:**

| Event | From | Effect |
| --- | --- | --- |
| `WorkflowCompleted`, `WorkflowCancelled` | Core | settle requisitions |
| `StockMoved` (source `stores.issue`, `stores.return`) | Inventory | record values on lines and movements, then raise `StoreConsumptionValued` |
| `GoodsReceived` (Inventory's contract) | Procurement | while Inventory is off, `receipt` movements into a store location |

**Inventory side (the minimal change to Inventory).** Inventory listens to
`StoreIssued` and `StoreReturned` (only when Inventory is active, and only
for `ledger = inventory`), queues a job with the event's lines, and posts
through its own ledger with source types `stores.issue` and
`stores.return` and movement kinds `store_issue` and `store_return` (added
to its kinds). `StockMoved` gains each movement's `source_line_id`, so a
listener can match movements to its lines.

## Permissions

| Permission | What it allows |
| --- | --- |
| `stores.requisition.view` | Requisitions at the place |
| `stores.requisition.create` | Ask a store for goods |
| `stores.requisition.approve` | Act on the requisition flow (WF-08) |
| `stores.requisition.cancel` | Cancel a pending or untouched approved requisition |
| `stores.issue.view` | Issues at the place |
| `stores.issue.create` | Issue against a requisition; close one with quantities open |
| `stores.issue.acknowledge` | Acknowledge receipt for the department |
| `stores.return.view`, `stores.return.create` | Returns to store |
| `stores.ledger.view` | The store ledger and stock on hand |
| `stores.stock.adjust` | Opening stock and adjustments in the own ledger |
| `stores.report.view` | Consumption reports |
| `stores.value.view` | Values everywhere in Stores (RBAC-05) |

Role templates (RBAC-03): **Owner** everything; **Admin** `stores.*.view`
(views, reports and values); **Branch Manager** `stores.*`;
**Storekeeper** `stores.*` except `stores.requisition.approve`,
`stores.issue.acknowledge` (the department does) and `stores.value.view`;
**Accountant** `stores.*.view`; **Read-only Auditor** `*.view`;
**Procurement Officer** `stores.requisition.view`, `stores.ledger.view`,
`stores.report.view` (no values); **Approver** `*.approve` and
`stores.requisition.view`.

## API

Prefix `/api/v1`, people's tokens, every route behind `module:stores`
(403 `module_inactive`). Lists follow ADR 008 (`?search`, `?sort`,
`?per_page`, `?page`, `?format=csv|xlsx|pdf`, `?columns[]`, `?company`,
`?branch`, `?location`, `?from`, `?to`, `?status`). Quantities are
decimal strings; money is `{amount_minor, currency}`.

| Route | Permission | Notes |
| --- | --- | --- |
| `GET stores/requisitions`, `GET …/{stores_requisition}` | `requisition.view` | `?department`, `?cost_centre`; detail has lines, issues and the flow |
| `POST stores/requisitions` | `requisition.create` at the store | `{location_id, department_id, cost_centre_id?, needed_by?, note?, lines: [{item_id, uom_id?, qty, note?}]}` |
| `POST stores/requisitions/{stores_requisition}/cancel` | `requisition.cancel` | `{reason}` |
| `POST stores/requisitions/{stores_requisition}/close` | `issue.create` | `{reason}`; approved or partially issued |
| `POST stores/requisitions/{stores_requisition}/issues` | `issue.create` at the store | `{note?, lines: [{requisition_line_id, qty}]}` |
| `GET stores/issues`, `GET …/{stores_issue}` | `issue.view` | `?status=issued\|acknowledged`, `?department` |
| `POST stores/issues/{stores_issue}/acknowledge` | `issue.acknowledge` at the store | `{note?}` |
| `GET stores/issues/{stores_issue}/print` | `issue.view` | `?document=delivery_note\|issue_voucher`, `?format=html\|pdf` |
| `POST stores/issues/{stores_issue}/returns` | `return.create` at the store | `{reason, note?, lines: [{issue_line_id, qty}]}` |
| `GET stores/returns`, `GET …/{stores_return}` | `return.view` | |
| `GET stores/ledger` | `ledger.view` | `?item`, `?kind` |
| `GET stores/stock` | `ledger.view` | Own ledger balances; `?item` |
| `POST stores/stock-entries` | `stock.adjust` at the store | `{location_id, kind: opening\|adjustment, reason?, note?, lines: [{item_id, uom_id?, qty (signed for adjustments)}]}`; 409 `inventory_active` |
| `GET stores/consumption` | `report.view` | `?group`, `?department`, `?cost_centre`, `?item` |
| `GET stores/status` | any `stores.*.view` | `{ledger: stores\|inventory, inventory_active}`: which ledger holds quantities now |

Errors: `not_a_store`, `location_archived`, `department_other_company`,
`cost_centre_other_company`, `uom_not_on_item`, `not_a_stock_item`,
`item_other_company`, `qty_precision`, `document_not_open`, `over_issue`,
`over_return`, `insufficient_stock`, `acknowledge_self`,
`inventory_active`, `opening_entry_required`.

## Screens (web)

Under **Stores** in the sidebar: Requisitions (list, new, detail with the
flow, issue and close), Issues (list, detail with acknowledge, return and
print), Returns (list, detail), Store ledger, Stock on hand (own ledger),
Consumption (grouping, filters, totals). Design-system components and
tokens only, en/fr, back-office list patterns as the Inventory screens
(ADR 008).

## Integrations

- **Stores → Inventory**: `StoreIssued`, `StoreReturned` move stock when
  both are active (STR-08); values come back through `StockMoved` (STR-09).
- **Procurement → Stores**: goods received at a store enter the own ledger
  while Inventory is off (STR-07). With Inventory on they go to Inventory.
  Raising a purchase requisition from a store requisition is Procurement's
  (later).
- **Stores → Accounting**: `StoreConsumptionValued` (STR-16).

## Open questions and decisions taken

Decisions taken while building (no owner input asked, per instructions):

1. **The ledger is chosen per document**, when it is saved, and kept on
   it: the module state can change between an issue and its return (or
   before Inventory's queued job runs), so an Inventory-ledger document
   always posts through Inventory and a return always follows its issue.
   Module gating applies to new documents only.
2. **Own-ledger issues are refused beyond stock** (Stores holds the
   numbers and can refuse at once); Inventory-ledger issues are never
   refused and are flagged by Inventory (Stores cannot ask Inventory
   synchronously, and the goods have physically left).
3. **Switching Inventory on does not carry Stores' own balances over.**
   The owner enters Inventory opening stock for the stores; Stores' own
   history stays readable.
4. **No costs without Inventory.** Stores does not keep its own costing;
   consumption without Inventory is in quantities.
5. **Batches and serials** are not captured on issues: Inventory takes
   batches earliest expiry first and flags serial items, as it does for
   POS sales.
6. **Stores are locations of type `store` or `warehouse`**, so a central
   warehouse can issue to departments too.
7. **A return of an Inventory-ledger issue whose issue has not been posted
   yet** (queued jobs out of order) waits and is retried by its job.
8. **Values are recorded once, and never lost.** The movement table is
   append-only except that a value may be written once into a row that
   has none; the recording listener is queued and retried, and a
   redelivered Inventory posting raises `StockMoved` again.
9. **Accounting posts Stores' consumption from `StoreConsumptionValued`**
   and skips `StockMoved` for `stores.*` sources (one journal per
   movement, with the department and cost centre).
10. **After an Inventory period the own ledger restarts from an opening
    entry.** When Inventory is switched off again, a store's own balances
    count only what moved since its last Inventory-ledger movement, and
    own-ledger issues and adjustments wait for an opening stock entry
    there (`opening_entry_required`), so stale quantities are never
    issued against.
11. **Unit factors are fixed per line.** Issues and returns convert with
    the factor stored on the requisition or issue line, so changing an
    item's unit factor later never changes stock already asked for or
    issued.

Open for the owner:

- Should a requisition be able to raise a purchase requisition when the
  store is short (Procurement)?
- Should department heads be able to set a monthly consumption budget per
  department and item?
