# Van sales (route selling) module spec

Source: concept note section 3 (route / van sales: mobile ordering in the
field, van stock, cash and mobile money collection, end-of-day
reconciliation); platform core spec (TEN-01, TEN-06, RBAC-01 to RBAC-08,
RBAC-06, WF-01, APR-07, NUM-02, AUD-01, AUD-02, NFR-04, NFR-07, NFR-08);
docs/modules/pos.md (the sale path, POS-09), docs/modules/inventory.md
(transfers, INV-08), docs/modules/ecommerce.md (rider privacy, ECOM-40);
ADR 004 (offline sync). When this file and the core spec disagree, the core
spec wins.

## Scope

Van sales is a module (`api/modules/VanSales`, key `van_sales`, permissions
`van_sales.*`, tables `vs_*`), sold and switched on per tenant like every
other module (catalogue key `van_sales`, per device, RBAC-08). It requires
POS. Routes, menus, permissions, sync sources and listeners exist only while
the tenant's subscription has it. The `van_sales` business type suggests it
by default in the module picker.
**Price to set:** the catalogue row is a placeholder equal to Inventory's
(KES 1,500.00 and USD 12.00 per device, that is per van, per month) until
the owner sets it; a price change is a new catalogue price row.

- **A van sale is a normal POS sale.** The rep sells on the van's device with
  the POS app; the sale is uploaded through `POST pos/sales`, so payments,
  M-Pesa, fiscal submission (eTIMS, DGI), Loyalty, Accounting and stock use
  the existing path. Van sales never reads or writes POS, Inventory or CRM
  tables: it links to a sale through the sale's `extensions.van_sales` and
  the `SaleCompleted`, `SaleVoided` and `SaleRefunded` events, to stock
  through Inventory's contracts, and to customers through core parties.
- **A van is a core location** of type `store` (no new location type; the
  van is marked by its `vs_vans` row), so its stock, sales and receipts are
  the location's. A van's device is a core device paired at that location.
- **Credit is the module's own ledger.** POS has no customer balance, so
  Van sales keeps the route credit ledger (`vs_credit_entries`): credit
  sales add, collections and credit refunds subtract. The limit is the
  customer's core credit limit (changed through the core flow
  `core.credit_limit_change`).
- **Money** is `bigint` minor units with a currency (ADR 003). Quantities are
  decimal strings in the item's base unit. Ids are UUID v7; the device makes
  the id of a run, visit, collection and count.
- **Offline.** The rep sells, visits and collects for 7 days without a
  connection (NFR-04). Everything the day needs is pulled before leaving;
  everything it makes is uploaded idempotently when the device is back.
- **Without Inventory** a load sheet is a plain list the rep confirms (no
  transfer is made); with it, load-out and unload are Inventory transfers.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| VAN-01 | **Module gate.** Routes, permissions, sync sources and listeners exist only while the tenant has the module (RBAC-08); it works with core and POS only, Inventory switches on load-out and unload by transfer. | Must |
| VAN-02 | **Vans.** A van has a name, a plate, a stock location (a core location of type `store`, made with the van or chosen), one rep (a user) and optionally the device paired at that location. Vans are archived, never deleted (TEN-06). Changing the rep or device is audited. | Must |
| VAN-03 | **Routes.** A route has a name, the weekdays it is visited (ISO 1 to 7), an optional default van, and belongs to a company and branch. Archived, never deleted. | Must |
| VAN-04 | **Stops.** A route lists customer stops in order: a core customer (party with the customer role), a position and an optional visit-day override (else the route's days). A customer is once on a route. Replacing the list archives the stops left out and keeps their visit history. | Must |
| VAN-05 | **Route plan for the day.** For a date the plan lists the stops due (stop days, else route days, in the company time zone) in order, each with its status for that date's run: `pending`, `visited`, `sold`, `no_sale`, `skipped`. The plan is also computed on the device from the pulled routes. | Must |
| VAN-06 | **Runs.** A run is a van's selling day on a route, started by the rep on the device (`started_at`, date in the company time zone). One open run per van; a run is `open`, `counting` (end-of-day count submitted, waiting) or `closed`. | Must |
| VAN-07 | **Load sheet.** A supervisor requests a load: items and quantities from a warehouse location to the van (Inventory transfer, INV-08, with its approval flow); the sheet follows the transfer: `awaiting_approval`, `approved`, `released`, `confirmed`, `rejected`, `cancelled`. A storekeeper releases it (dispatch). | Must |
| VAN-08 | **Rep confirms the load.** The rep counts each line on the device and confirms: what arrived enters the van, a shortfall is written off in transit by Inventory with a reason. A sheet is confirmed once; selling needs a confirmed sheet only as a warning on the device, never as a block on the server. | Must |
| VAN-09 | **Stop visits.** A visit records the stop's status: `visited`, `sold` (set when a van sale of that visit is linked), `no_sale` with a reason (`closed`, `no_need`, `has_stock`, `no_money`, `credit_hold`, `not_found`, `other`) or `skipped`, with arrival and leaving times and a note. Visits are made on the device by id, last writer wins by `updated_at`, and may be to a customer not on the route. | Must |
| VAN-10 | **Selling on the route.** A van sale is a POS sale of the van's device with `extensions.van_sales = {run_id, visit_id?, credit?, exchange_for_sale_id?}`: cash, M-Pesa, credit, or a mix. The server links it to the run on `SaleCompleted`, records its tenders, stock lines and credit entry once per sale; a void takes them back. A sale of another van's run is not linked. | Must |
| VAN-11 | **Credit within the limit.** The device allows a credit tender only up to the customer's available credit (limit less balance) in the limit's currency, using the last synced limit and balance. The server never refuses a stored sale: on upload it recomputes and flags `over_limit`, `no_credit_limit`, `limit_currency_differs`, `credit_without_customer`, `limit_changed` (the device used another limit) and, when the device decided offline, `credit_offline` (company setting, on by default). A flagged sale waits in the credit review queue. | Must |
| VAN-12 | **Credit review.** Flagged credit sales are listed (`van_sales.credit.review`, at the sale's location) and reviewed with a note; the review is audited. Reviewing changes no money. | Must |
| VAN-13 | **Credit ledger.** A customer's route balance per currency is the sum of its entries: `sale` (+), `collection` (−), `refund` (−, when the refund pays back credit), and reversals on void. Entries are append-only and once per source. | Must |
| VAN-14 | **Receipts and numbers (NUM-02).** Sale receipts come from the device's `pos.receipt` range; a collection receipt takes the next number of the same range, claimed on upload. A number outside the device's ranges rejects the collection (`receipt_range_unknown`, `receipt_number_mismatch`). | Must |
| VAN-15 | **Fiscal.** Sales are submitted by POS's fiscal queue (eTIMS, DGI) when the device is online again; nothing is added here, and a sale is never held back for it. | Must |
| VAN-16 | **Returns and exchanges.** A customer return is a POS refund of a van sale at the van; its goods come back to the van's stock (Inventory's own refund path) and count in the run's load; its refund payments reduce the run's expected cash or credit. An exchange is a refund plus a new sale carrying `exchange_for_sale_id`. Damaged returns are declared at the count. | Must |
| VAN-17 | **Collections.** The rep collects a customer's outstanding balance in cash or mobile money: `{customer, currency, amount, method}`; it reduces the balance and adds to the run's expected cash or mobile money. Collections are append-only and idempotent by id; a supervisor with `van_sales.collection.void` voids one with a reason (audited). A collection above the balance is stored and flagged `exceeds_balance`; money in is never refused. | Must |
| VAN-18 | **End-of-day count.** The rep submits, per item, the quantity returned (good) and damaged, and per currency the cash and mobile money counted. A recount after a rejection is a new attempt. | Must |
| VAN-19 | **Stock reconciliation.** Per item: `load = sold + returned + damaged + variance`, where `load` is the confirmed load of the run plus customer returns taken back, `sold` is the net quantity of its van sales (voids out), and `variance` (a shortage is positive, a surplus negative) is what is left over. Quantities are in the item's base unit. | Must |
| VAN-20 | **Cash and mobile money reconciliation.** Per currency and kind (`cash`, `mobile_money`): expected = tenders of its sales and collections less change given, refunds and voids; counted is the rep's figure; variance = counted − expected. Credit never counts as cash. | Must |
| VAN-21 | **Variance approval.** A count with any variance, or any damaged quantity (a write-off is a decision), is a document of the workflow engine (`van_sales.reconciliation`, WF-01) with its flow; the default flow is one approval by a Route supervisor (any one, APR-07: never the rep who counted), escalated to the Branch managers after two business days. A tenant may change it and route on `cash_variance` (absolute base-currency amount) and `stock_variance_lines`. A count with neither closes the run at once. Rejection reopens the count for a recount. | Must |
| VAN-22 | **Posting on approval.** Approved: the damaged quantity and the shortage are written off from the van by Inventory (`StockWrittenOff`, audited, once per count); a surplus and the cash variance are recorded on the reconciliation and the audit log, not posted. The run closes. | Must |
| VAN-23 | **Unload.** The counted good quantity (not above what the van holds) goes back to the warehouse by an Inventory transfer, requested when the count is submitted; a storekeeper with `van_sales.unload.receive` receives it with the counts at the warehouse (dispatch and receipt in one step), differences being Inventory's transit loss. | Must |
| VAN-24 | **GPS check-in (optional).** A company setting (`gps_checkin`: `off` or `optional`, default off). When optional, the app asks once with an in-app explanation (en, fr) and the phone's permission, only in the foreground; refusing leaves the rep working. One point is stored per visit at arrival (latitude and longitude rounded to 4 decimals, accuracy, time); no trail, no background tracking. Positions are the rep's and customer's personal data (NFR-08): read only with `van_sales.visit.track`, never in the audit log or other lists, and cleared after `gps_retention_days` (1 to 90, default 30). | Should |
| VAN-25 | **Reports.** Per company, branch, route, rep or van and period: sales per route and rep (count, totals per currency), strike rate (visits that sold ÷ visits), collections per currency, cash and stock variances, credit outstanding, and stock on vans (Inventory's levels of the items last loaded). | Must |
| VAN-26 | **Permissions and roles.** `van_sales.resource.action`; role templates **Van rep** (own van's day: load confirm, visits, collections, count, sells and refunds at the van through POS) and **Route supervisor** (vans, routes, loads, review, reconciliation reports); the Branch manager holds `van_sales.*` except settings; the Admin sets up vans, routes and settings. | Must |
| VAN-27 | **Offline.** Routes, stops, customers' credit data, load sheets and the open run are pulled (core sync API); runs, visits, collections and counts are pushed by `POST van-sales/device/sync` (idempotent, per-record results). Sales stay append-only and idempotent (POS-09). | Must |
| VAN-28 | **Audit.** Create, update and archive of vans, routes, stops and settings; load requests, releases and confirmations; collections and voids; credit reviews; counts, approvals and write-offs are audited (AUD-01, AUD-02). | Must |

## Permissions

| Permission | Allows |
| --- | --- |
| `van_sales.van.view`, `van_sales.van.manage` | See / set up vans (rep, device, location) |
| `van_sales.route.view`, `van_sales.route.manage` | See the routes and plan / set up routes and stops |
| `van_sales.load.view`, `.create`, `.release`, `.confirm` | Read load sheets; request a load; release it from the warehouse; confirm what arrived on the van |
| `van_sales.run.view`, `van_sales.run.start` | Read runs; start the day (the rep) |
| `van_sales.visit.record`, `van_sales.visit.track` | Record stop visits; read check-in positions |
| `van_sales.collection.view`, `.record`, `.void` | Read collections; collect; void one |
| `van_sales.credit.view`, `van_sales.credit.review` | Read customers' route balances and the review queue; review a flagged credit sale |
| `van_sales.reconciliation.view`, `.submit`, `.approve` | Read counts; count the van; the default approver of a variance (the workflow checks it is not the requester) |
| `van_sales.unload.receive` | Receive the unload at the warehouse (checked at the warehouse; the storekeeper holds it with `van_sales.load.release`) |
| `van_sales.settings.edit` | A company's GPS and credit settings |
| `van_sales.report.view` | Reports |

Selling and refunding use core's `pos.sale.create`, `pos.sale.refund`, and the
shift's `pos.shift.*`; the rep's role holds them at the van's location.

## API

Prefix `/api/v1`, every route behind `module:van_sales`. Amounts are digit
strings, times ISO 8601 UTC, quantities decimal strings.

**Back office (people's tokens, checked at the van's location, RBAC-04).**

| Route | Notes |
| --- | --- |
| `GET van-sales/vans`, `POST van-sales/vans`, `PUT van-sales/vans/{id}`, `POST .../archive` | `{branch_id, name, plate?, rep_id, device_id?, location_id?}`; without `location_id` a `store` location named like the van is made |
| `GET/POST van-sales/routes`, `PUT van-sales/routes/{id}`, `POST .../archive` | `{company_id, branch_id, name, visit_days: [1..7], van_id?}` |
| `GET van-sales/routes/{id}`, `PUT van-sales/routes/{id}/stops` | `{stops: [{customer_id, visit_days?}]}` replaces the list in order |
| `GET van-sales/plan?date&route_id` | VAN-05 |
| `GET van-sales/runs`, `GET van-sales/runs/{id}` | Filters `van_id`, `route_id`, `rep_id`, `status`, `from`, `to`; detail has visits, sales, collections, loads, the latest count |
| `GET van-sales/runs/{id}/positions` | Check-in positions (`van_sales.visit.track`) |
| `GET/POST van-sales/loads`, `GET van-sales/loads/{id}` | `{van_id, from_location_id, note?, run_id?, lines: [{item_id, qty}]}` |
| `POST van-sales/loads/{id}/release`, `POST .../cancel` | Dispatch from the warehouse; cancel (`{reason}`) |
| `GET van-sales/collections`, `POST van-sales/collections/{id}/void` | `{reason}` |
| `GET van-sales/accounts`, `GET van-sales/accounts/{party}` | Balances per currency, limit, entries |
| `GET van-sales/credit-reviews`, `POST van-sales/credit-reviews/{id}/review` | `{note?}` |
| `GET van-sales/reconciliations`, `GET van-sales/reconciliations/{id}` | Lines, tenders, status, the flow's document id (decided in the approvals inbox) |
| `POST van-sales/reconciliations/{id}/unload/receive` | `{lines: [{item_id, qty_received}]}` |
| `GET/PUT van-sales/companies/{company}/settings` | `{gps_checkin, gps_retention_days, flag_offline_credit}` |
| `GET van-sales/reports/summary?from&to&company_id\|branch_id\|route_id\|rep_id\|van_id`, `GET van-sales/reports/van-stock` | VAN-25 |

**Device (device token; place from the device).**

| Route | Notes |
| --- | --- |
| `POST van-sales/device/sync` | `{runs: [], visits: [], collections: [], counts: []}`, at most 100 records in all; `{results: {runs: [], visits: [], collections: [], counts: []}}`, one result per record in order |
| `POST van-sales/device/loads/{id}/confirm` | `{user_id, lines: [{id, qty_received}]}` |
| Pull (core sync API) | `van_sales_van`, `van_sales_routes`, `van_sales_accounts`, `van_sales_loads`, `van_sales_runs` |

Records of `device/sync`:

- run `{id, route_id?, user_id, started_at}`;
- visit `{id, run_id, stop_id?, customer_id, user_id, status, no_sale_reason?, note?, arrived_at?, left_at?, check_in?: {lat, lng, accuracy_m, at}, updated_at}`;
- collection `{id, run_id, visit_id?, customer_id, user_id, payment_method_id, currency, amount_minor, provider_reference?, receipt_seq, receipt_number, number_range_id?, collected_at}`;
- count `{id, run_id, user_id, submitted_at, note?, lines: [{item_id, returned_qty, damaged_qty}], tenders: [{kind: cash|mobile_money, currency, counted_minor}]}`.

A result is `{id, status: stored, ...}` or `{id, status: rejected, code, field?}`; the same record again answers the same. A count's result carries `reconciliation: {id, status: balanced|pending, stock_variance_lines, tenders: [{kind, currency, expected_minor, counted_minor, variance_minor}]}`. The whole request is 422 `upload_rejected` when nothing is stored.

**Pull rows** (one object per id): `van_sales_van` `{id, name, plate, rep_id, rep_name, location_id, gps_checkin, gps_retention_days}`; `van_sales_routes` `{id, name, visit_days, stops: [{id, customer_id, customer_name, phone, position, visit_days}]}`; `van_sales_accounts` `{id: customer_id, credit_limit_minor, credit_limit_currency, balances: [{currency, amount_minor}]}`; `van_sales_loads` `{id, run_id, number, status, lines: [{id, item_id, item_name, qty, qty_confirmed}]}`; `van_sales_runs` `{id, route_id, run_date, status, started_at, count: {id, status, attempt} | null, visits: [...]}`.

**Response shapes** (Laravel style `{data: ...}`; lists are `{data: [...]}` whole unless noted; `GET runs`, `collections`, `credit-reviews` and `reconciliations` are paginated with `links` and `meta`).

- Van `{id, company_id, branch_id, location_id, location_name, name, plate, rep_id, rep_name, device_id, device_name, archived_at}`.
- Route `{id, company_id, branch_id, van_id, van_name, name, visit_days, stop_count, archived_at}`; the detail adds `stops: [{id, customer_id, customer_name, position, visit_days}]`.
- Plan `{data: {date, routes: [{route_id, name, stops: [{stop_id, customer_id, customer_name, position, status, visit_id}]}]}}`.
- Run `{id, van_id, van_name, route_id, route_name, rep_id, rep_name, run_date, status, started_at, closed_at, visit_count, sold_visit_count, sales_count, sales_totals: [{currency, total_minor}], reconciliation_status}`; the detail adds `visits: [{id, stop_id, customer_id, customer_name, status, no_sale_reason, note, arrived_at, left_at}]`, `sales: [{id, sale_id, visit_id, customer_id, customer_name, currency, total_minor, credit_minor, status, flags, review_status, sold_at}]`, `collections: [Collection]`, `loads: [Load]`, `reconciliation: Reconciliation | null`.
- Load `{id, van_id, van_name, run_id, from_location_id, from_location_name, number, status, note, requested_by_name, requested_at, released_at, confirmed_at, lines: [{id, item_id, item_name, qty, qty_confirmed}]}`.
- Collection `{id, run_id, customer_id, customer_name, currency, amount_minor, method_type, receipt_number, provider_reference, collected_at, status, flags, void_reason}`.
- Account `{customer_id, customer_name, credit_limit_minor, credit_limit_currency, balances: [{currency, amount_minor}]}`; the detail adds `entries: [{id, kind, currency, amount_minor, occurred_at, run_id}]`.
- Credit review `{id, sale_id, run_id, van_name, rep_name, customer_id, customer_name, currency, total_minor, credit_minor, limit_minor, balance_before_minor, flags, sold_at, review_status: pending|reviewed, reviewed_by_name, reviewed_at, review_note}`.
- Reconciliation `{id, run_id, run_date, van_id, van_name, rep_name, attempt, status: pending|balanced|approved|rejected, submitted_at, decided_at, decided_by_name, note, stock_variance_lines, cash_variance_base_minor, lines: [{item_id, item_name, load_qty, sold_qty, returned_qty, damaged_qty, variance_qty}], tenders: [{kind, currency, expected_minor, counted_minor, variance_minor}], unload: {status, number} | null}`; it is approved or rejected in the approvals inbox (document type `van_sales.reconciliation`, document id = the reconciliation id).
- Settings `{company_id, gps_checkin, gps_retention_days, flag_offline_credit}`.
- Summary `{data: {from, to, rows: [{route_id, route_name, rep_id, rep_name, visits, sold_visits, strike_rate, sales_count, sales: [{currency, total_minor}], collections: [{currency, amount_minor}]}], cash_variances: [{run_id, run_date, van_name, rep_name, kind, currency, variance_minor}], stock_variances: [{run_id, run_date, van_name, item_id, item_name, variance_qty}], credit_outstanding: [{currency, amount_minor}]}}`; `strike_rate` is a number 0 to 100 with one decimal.
- Van stock `{data: [{van_id, van_name, items: [{item_id, item_name, qty}]}], inventory: true|false}`.
- Positions `{data: [{visit_id, customer_name, lat, lng, accuracy_m, at}]}`.

Errors: `van_unknown`, `route_unknown`, `run_unknown`, `run_closed`, `customer_unknown`, `rep_unknown`, `location_unknown`, `load_not_open`, `load_not_released`, `load_confirmed`, `visit_invalid`, `collection_invalid`, `receipt_range_unknown`, `receipt_number_mismatch`, `payload_mismatch`, `count_open`, `count_not_pending`, `unload_not_ready`, `inventory_required`, `upload_rejected`.

**Decisions to confirm.** (1) The catalogue price is a placeholder (price to set). (2) A van's stock location is a `store` location, not a new `van` type (a new type needs a core migration and billing rules). (3) Vans are unloaded every day: stock kept on the van overnight is not supported by the equation (it must be counted as returned). (4) A stock surplus and a cash variance are recorded, not posted to stock or accounts. (5) Collection receipts share the device's `pos.receipt` range with sales. (6) The default variance approver is the Route supervisor, any one, never the rep. (7) Credit in a currency other than the customer's limit currency is flagged, not converted.

## Deferred

Van-to-van transfers, customer orders taken now and delivered later (pre-sell), route optimisation and maps, targets per rep, commission, invoice-level allocation of collections, posting variances to Accounting, customer signature capture.
