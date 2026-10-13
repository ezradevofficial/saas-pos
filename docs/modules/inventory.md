# Inventory module spec (phase 7A)

Source: concept note sections 3 (retail, supermarket and pharmacy rows:
variants, serial numbers, barcode labels, batch and expiry), 5 (Inventory
row and the integration rule) and 6.3 (stock transfer flow, threshold
automation); platform core spec (TEN-01, TEN-04, TEN-06, RBAC-01 to RBAC-09,
MD-02, CUR-02, CUR-04, WF-01, WF-02, APR-01, APR-07, NUM-01, TPL-01, LAY-01,
LAY-04, NOT-02, AUD-01, IMP-01, IMP-02, EXP-01, NFR-04); ADR 002 (RLS),
ADR 003 (money), ADR 004 (sync), ADR 008 (lists), ADR 014 (imports). When
this file and the core spec disagree, the core spec wins.

## Scope

Inventory is a module (`api/modules/Inventory`, permissions `inventory.*`,
catalogue key `inventory`), sold and switched on per tenant (RBAC-08). It
works with only the core installed: it builds on the core items, units and
locations (MD-02, TEN-04) and keeps every stock fact in its own tables. It
never reads another module's tables. POS sales reach it through the POS
events (`SaleCompleted`, `SaleVoided`, `SaleRefunded`), whose stock lines the
POS module itself provides; Procurement will reach it through the
`GoodsReceived` contract defined here.

- **Stock items.** A core item of type `stock` is tracked. Services,
  non-stock items and kits never move stock (kits: see open questions).
- **Locations.** Stock is kept per core location (any type: outlet,
  warehouse, store). A location belongs to one branch and company; costs are
  kept per company.
- **Quantities** are exact decimals: `numeric(20,6)` in the item's base unit.
  A quantity typed in another unit of the item is converted with the core
  unit factor (exact, `brick/math`, 6 decimals half up). Typed quantities
  follow the unit's precision: whole numbers for `count` units, 3 decimals
  for weight, volume, length and time units. Quantities travel as decimal
  strings.
- **Costs** are `bigint` minor units of the company's base currency
  (ADR 003), taken under `BaseCurrencyLock` (CUR-02).

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| INV-01 | **Stock settings per item.** A stock item's tracking is `none`, `batch` or `serial`, with an expiry date per batch when `track_expiry` is on. Kept in Inventory's own table, not on the core item. Tracking cannot change once the item has stock movements (`tracking_locked`). | Must |
| INV-02 | **Variants.** An item can be linked as a variant of a parent item with attribute values (`{"size": "M", "colour": "Red"}`). Each variant is a core item with its own code, barcode and price, so the till sells it as is; stock is kept per variant and the parent's detail rolls them up. A parent cannot itself be a variant; an attribute set is unique among a parent's variants. | Must |
| INV-03 | **Units and conversions.** Any quantity may be entered in any unit of the item (core item units and factors, MD-02) and is stored in the base unit (see Scope). A unit the item does not have is refused (`uom_not_on_item`). | Must |
| INV-04 | **Append-only stock ledger.** Every change of stock is a row of `inventory_movements` (kind, item, location, batch, signed base quantity, signed value, source document). The database refuses updates and deletes of movements (trigger, also for the owner). Balances per location (`inventory_balances`) and per batch (`inventory_batch_balances`) are updated in the same transaction with atomic increments, so two moves on one balance never lose an update. | Must |
| INV-05 | **Multi-location.** Stock on hand per item and location, visible only at the locations where the reader holds the permission (RBAC-04). Archived locations keep their history (TEN-06) and take no new movements. | Must |
| INV-06 | **Batches and expiry.** Receipts of batch items name a batch number (and expiry when tracked); balances are kept per batch. An issue that names no batch (a sale) takes the earliest expiry first (FEFO), then the oldest batch; whatever is not covered goes to the unbatched balance and is flagged. | Must |
| INV-07 | **Serial numbers.** Serial items need one serial per unit on receipts, adjustments and transfers (whole quantities). Each serial has a status (`in_stock`, `in_transit`, `issued`) and its current location; a serial is unique per item. Serial capture at the till is deferred: a POS sale of a serial item moves the quantity and is flagged `serial_not_captured`. | Must |
| INV-08 | **Transfers with approval and in-transit.** A transfer moves stock between two locations of one company (`transfer_cross_company` otherwise). It is a workflow document (`inventory.transfer`, WF-01) whose default flow is one approval by a Branch Manager at the source; a tenant may publish a flow without approval ("Transfer → Receive"). Approved transfers are dispatched (stock leaves the source, status `in_transit`, serials `in_transit`) and received (stock enters the destination). A receipt below the dispatched quantity writes the difference off as `transfer_loss`, with a reason. Pending or approved transfers can be cancelled; dispatched ones cannot. | Must |
| INV-09 | **Stock counts.** A count is `full` (every item with stock or a reorder rule at the location) or `cycle` (chosen items or a category). Starting it freezes the location for those items: expected quantities are snapshot and adjustments and transfer dispatches or receipts of those items there are refused (`count_in_progress`); sales and goods receipts go on and are flagged `during_count`. Counted quantities are entered, then submitted: no variance posts at once; a variance starts the `inventory.count` flow (variance approval by a Branch Manager) and posts on approval. Posting moves stock by the variance: counted − (expected at the freeze + what moved there since), costed like an adjustment. | Must |
| INV-10 | **Adjustments with reasons and approval.** An adjustment (`damage`, `expiry`, `theft`, `found`, `correction`, `internal_use`, `other`, plus a note) changes stock up or down at one location. It is a workflow document (`inventory.adjustment`) whose default flow is one approval by a Branch Manager at the location (APR-07: never the requester), and posts on approval. | Must |
| INV-11 | **Reorder levels and low-stock alerts.** A reorder rule per item and location holds a reorder level and quantity. When stock there falls to or below the level, `StockLow` is raised and holders of `inventory.reorder.edit` at the location are notified once (`inventory.stock.low`, NOT-02); the alert re-arms when stock rises above the level. | Must |
| INV-12 | **Costing per company.** Each company uses FIFO or weighted average (default weighted average), fixed once the company has a movement (`costing_method_locked`). FIFO keeps cost layers; weighted average keeps quantity and value. Issues are costed by the method; returns from a sale come back at the cost they went out at; transfers between locations of a company do not change cost. Issuing more than is on hand costs the shortfall at the last receipt's unit cost (flagged `costed_at_last_cost`); the receipt that covers it records the difference as `cost_variance_minor`. Every movement stores its value, so reports never recompute. | Must |
| INV-13 | **Negative stock.** Sales never fail for lack of stock: a POS sale is always posted and a movement leaving stock below zero is flagged `negative_stock`. Manual issues (adjustments, transfers, counts) may go negative only when the company allows it (`allow_negative`, default on); otherwise they are refused with `insufficient_stock`. | Must |
| INV-14 | **POS integration.** When both POS and Inventory are active (RBAC-08), a completed sale moves its stock items out of the till's location, a void brings the whole sale back and a refund brings the refunded quantities back, at their original cost. Idempotent: an event delivered twice moves stock once. With Inventory off, nothing happens. | Must |
| INV-15 | **Goods received (Procurement contract).** `Modules\Inventory\Contracts\GoodsReceived` is the event a receiving module dispatches after commit: company, location, time, reference and lines (item, unit, quantity, line cost and currency, batch, expiry, serials). Inventory posts them as receipts with cost layers (a cost in another currency is converted at the company's rate, CUR-04), once per line. | Must |
| INV-16 | **Events raised** (after commit): `StockMoved` (every posting, with each movement's quantity and value), `StockAdjusted`, `StockCountPosted`, `TransferReceived` and `StockLow`, carrying the company, base currency and values Accounting needs to post them later. | Must |
| INV-17 | **Stock on the till.** The read-only sync entity `inventory_stock` (incremental, ADR 004) carries the stock on hand of each item at the device's location, so the till can show it. It never blocks a sale. | Must |
| INV-18 | **Opening stock.** Entered one line at a time (`POST inventory/opening-stock`) or imported (IMP-01, importer `opening_stock`): item code, location code, quantity, unit, unit cost, batch, expiry, serials. An import is undone (IMP-02) by reversing movements, refused once a later movement of the item at that company exists. | Must |
| INV-19 | **Back-office lists and exports.** Stock on hand, movements, transfers, counts, adjustments, reorder rules and valuation, with search, filters, sort and export (EXP-01), scoped by location (RBAC-04). Costs and values are shown only to holders of `inventory.valuation.view` at the place (RBAC-05). | Must |
| INV-20 | **Valuation report.** Quantity, value and unit cost per item and company in the base currency, with a per-location breakdown (quantity × the company's unit cost), and totals per currency. | Must |
| INV-21 | **Barcode labels.** Labels print through document templates (TPL-01) as the document type `inventory.label` (58 mm by default): item name and code, price from a price list, unit, batch and expiry, and a Code 128 barcode. Several items and copies print as one HTML page set or PDF. | Must |
| INV-22 | **Dashboard widgets** (LAY-01): `inventory.low_stock` (list) and `inventory.stock_value` (KPI, needs `inventory.valuation.view`). | Must |
| INV-23 | **Numbering** (NUM-01): transfers `TR-{BRANCH}-{YYYY}-{00001}`, adjustments `ADJ-{BRANCH}-{YYYY}-{00001}`, counts `CNT-{BRANCH}-{YYYY}-{00001}`, yearly. | Must |
| INV-24 | **Audit** (AUD-01): settings, item stock settings and variant links, reorder rules, and every document's create, submit, decision, dispatch, receipt, posting and cancellation. The ledger itself is the record of every movement. | Must |

## Data model

Every table has `tenant_id` under forced RLS (TEN-01) and UUID v7 keys.
Quantities `numeric(20,6)` (base unit), costs `bigint` minor units with a
`currency char(3)` (company base currency).

| Table | Holds |
| --- | --- |
| `inventory_settings` | Per company: `costing_method` (`fifo`, `weighted_average`), `allow_negative`, `costing_locked_at` |
| `inventory_item_settings` | Per item: `tracking` (`none`, `batch`, `serial`), `track_expiry` |
| `inventory_variants` | A variant item, its `parent_item_id` and `attributes` (jsonb) |
| `inventory_batches` | Item, `batch_number` (unique per item, case-insensitive), `expires_on` |
| `inventory_serials` | Item, `serial_number` (unique per item), `status`, current `location_id` |
| `inventory_balances` | Company, location, item, `qty`; unique per location and item; stamped for sync (`sync_xid`, `sync_seq`) |
| `inventory_batch_balances` | Location, item, batch, `qty` |
| `inventory_movements` | Append-only ledger: company, location (null while in transit), item, batch, kind, `qty` (signed), entered unit and quantity, `value_minor` (signed), `currency`, `cost_variance_minor`, `source_type`, `source_id`, `source_line_id`, `occurred_at`, `flags`, `created_by`; unique per source line and kind |
| `inventory_movement_serials` | The serials a movement carried |
| `inventory_cost_states` | Per company and item: `qty`, `value_minor`, last receipt `last_cost_minor` and `last_cost_qty` |
| `inventory_cost_layers` | FIFO layers: company, item, the receiving movement, `qty`, `cost_minor`, `remaining_qty`, `remaining_cost_minor` |
| `inventory_reorder_rules` | Item, location, `reorder_level`, `reorder_qty`, `low_since`, `archived_at` |
| `inventory_transfers`, `inventory_transfer_lines` | Number, company, branch, from and to locations, status (`pending`, `approved`, `rejected`, `in_transit`, `received`, `cancelled`), people and times; lines with item, unit, quantity, base quantity, batch, serials, received and lost quantities |
| `inventory_adjustments`, `inventory_adjustment_lines` | Number, company, branch, location, reason, note, status (`pending`, `posted`, `rejected`, `cancelled`); lines with item, unit, signed quantity, base quantity, batch, expiry, serials, unit cost |
| `inventory_counts`, `inventory_count_lines` | Number, company, branch, location, kind, status (`counting`, `pending`, `posted`, `rejected`, `cancelled`); lines with item, batch, expected, counted and variance quantities and variance value |

Movement kinds: `opening`, `receipt`, `adjustment_in`, `adjustment_out`,
`count_gain`, `count_loss`, `transfer_out`, `transfer_in`, `transfer_loss`,
`sale`, `sale_return`, `sale_void`, `opening_reversal`. Sources:
`inventory.opening`, `inventory.import`, `inventory.adjustment`,
`inventory.transfer`, `inventory.count`, `pos.sale`, `pos.refund`,
`pos.void`, `procurement.goods_receipt`.

## Events

**Raised** (`Modules\Inventory\Events`, dispatched after commit, ids and
values only):

| Event | When | Carries |
| --- | --- | --- |
| `StockMoved` | Every posting | tenant, company, currency, source type and id, movements `[{id, kind, item_id, location_id, qty, value_minor}]` |
| `StockAdjusted` | An adjustment posted | the adjustment id, company, location, currency, total value in and out |
| `StockCountPosted` | A count posted | the count id, company, location, currency, gain and loss values |
| `TransferReceived` | A transfer received | the transfer id, company, from and to locations, currency, received and lost values |
| `StockLow` | Stock fell to or below a reorder level | item, location, company, quantity, reorder level and quantity |

**Consumed:**

| Event | From | Effect |
| --- | --- | --- |
| `SaleCompleted` | POS | `sale` movements out of the sale's location (only when Inventory is active) |
| `SaleVoided` | POS | `sale_void` movements back, at the sale's cost |
| `SaleRefunded` | POS | `sale_return` movements back for the refunded quantities, at the sale's cost |
| `GoodsReceived` | Procurement (contract defined here) | `receipt` movements with cost layers |
| `WorkflowCompleted`, `WorkflowCancelled` | Core | settle transfers, adjustments and counts |

The POS events carry ids; the POS module provides their stock lines
(`Modules\POS\Events\SaleStockLines`), so Inventory never reads POS tables.

## Permissions

| Permission | What it allows |
| --- | --- |
| `inventory.stock.view` | Stock on hand, item stock detail, movements, batches and serials |
| `inventory.valuation.view` | Costs and values everywhere, the valuation report |
| `inventory.item.configure` | Item tracking and variant links |
| `inventory.opening.create` | Enter or import opening stock |
| `inventory.reorder.view`, `inventory.reorder.edit` | Read and set reorder rules (`edit` also receives low-stock alerts) |
| `inventory.transfer.view`, `.create`, `.approve`, `.dispatch`, `.receive`, `.cancel` | Transfers |
| `inventory.adjustment.view`, `.create`, `.approve`, `.cancel` | Adjustments |
| `inventory.count.view`, `.create`, `.count`, `.approve`, `.cancel` | Counts (`count` enters quantities) |
| `inventory.settings.edit` | A company's costing method and negative-stock rule |
| `inventory.label.print` | Print barcode labels |

`approve` is the workflow act permission of each document type (WF-08).

Role templates (RBAC-03): **Owner** everything; **Admin**
`inventory.settings.edit`, `inventory.item.configure` and the views;
**Branch Manager** `inventory.*`; **Storekeeper** `inventory.*` except the
three `approve` permissions and `inventory.settings.edit`; **Accountant**
`inventory.*.view` and `inventory.valuation.view`; **Procurement Officer**
and **Read-only Auditor** `inventory.*.view`; **Approver** the `approve`
permissions (`*.approve`).

## API

Prefix `/api/v1`, people's tokens, every route behind `module:inventory`
(403 `module_inactive`). Lists follow ADR 008 (`?search`, `?sort`,
`?per_page`, `?page`, `?format=csv|xlsx|pdf`, `?columns[]`, `?company`,
`?branch`, `?location`). Quantities are decimal strings; money is
`{amount_minor, currency}`; `unit_cost` inputs are decimal strings in major
units of the company's base currency (up to 6 decimals).

| Route | Permission | Notes |
| --- | --- | --- |
| `GET inventory/companies/{company}/settings`, `PUT …` | `stock.view`; `settings.edit` at the company | `{costing_method, allow_negative, costing_locked}` |
| `GET inventory/stock` | `stock.view` | One row per item and location: `?category`, `?flag=low` |
| `GET inventory/movements` | `stock.view` | `?kind`, `?from`, `?to` |
| `GET inventory/items/{item}` | `stock.view` | Settings, variant link and variants, balances by location, batches, serials in stock, costs per company (valuation only) |
| `GET inventory/items/{item}/movements` | `stock.view` | The item's ledger |
| `PUT inventory/items/{item}/settings` | `item.configure` | `{tracking, track_expiry}` |
| `PUT inventory/items/{item}/variant` | `item.configure` | `{parent_item_id, attributes}`; `parent_item_id: null` unlinks |
| `POST inventory/opening-stock` | `opening.create` at the location | `{item_id, location_id, uom_id?, qty, unit_cost, batch_number?, expires_on?, serials?}` |
| `GET inventory/reorder-rules`, `PUT inventory/reorder-rules` | `reorder.view`; `reorder.edit` | `{item_id, location_id, reorder_level, reorder_qty}`; `reorder_level: null` archives the rule |
| `GET inventory/transfers`, `GET …/{inventory_transfer}` | `transfer.view` | Detail has lines and the flow (WF-10) |
| `POST inventory/transfers` | `transfer.create` at the source | `{from_location_id, to_location_id, note?, lines: [{item_id, uom_id?, qty, batch_id?, serials?}]}`; starts the flow |
| `POST inventory/transfers/{inventory_transfer}/dispatch` | `transfer.dispatch` at the source | approved → in transit |
| `POST inventory/transfers/{inventory_transfer}/receive` | `transfer.receive` at the destination | `{lines?: [{id, qty_received}], loss_reason?}`; everything when no lines |
| `POST inventory/transfers/{inventory_transfer}/cancel` | `transfer.cancel` | `{reason}`; pending or approved only |
| `GET inventory/adjustments`, `GET …/{inventory_adjustment}` | `adjustment.view` | |
| `POST inventory/adjustments` | `adjustment.create` at the location | `{location_id, reason, note?, lines: [{item_id, uom_id?, qty (signed), batch_id?, batch_number?, expires_on?, serials?, unit_cost?}]}`; starts the flow |
| `POST inventory/adjustments/{inventory_adjustment}/cancel` | `adjustment.cancel` | `{reason}` |
| `GET inventory/counts`, `GET …/{inventory_count}` | `count.view` | |
| `POST inventory/counts` | `count.create` at the location | `{location_id, kind, item_ids?, category_id?, note?}`; freezes and snapshots |
| `PUT inventory/counts/{inventory_count}/lines` | `count.count` | `{lines: [{id, counted_qty}]}` |
| `POST inventory/counts/{inventory_count}/submit` | `count.count` | Posts at once without variance, else starts the flow |
| `POST inventory/counts/{inventory_count}/cancel` | `count.cancel` | `{reason}` |
| `GET inventory/valuation` | `valuation.view` | One row per item and company: `?category`, `?location` |
| `POST inventory/labels` | `label.print` | `{labels: [{item_id, uom_id?, batch_id?, copies}], price_list_id?, format: html\|pdf}` |

Errors: `uom_not_on_item`, `not_a_stock_item`, `insufficient_stock`,
`count_in_progress`, `transfer_cross_company`, `same_location`,
`serials_required`, `serial_unknown`, `serial_not_in_stock`,
`serial_exists`, `batch_required`, `tracking_locked`,
`costing_method_locked`, `document_not_open`, `location_archived`.

## Screens (web)

Under **Inventory** in the sidebar: Stock on hand (with the item stock
detail: by location, batches, serials, movements, tracking and variant),
Movements, Transfers (list, new, detail with dispatch and receive),
Counts (list, new, count entry and submit), Adjustments (list, new,
detail), Reorder levels, Valuation, Labels, and the company settings
(costing method, negative stock). Design-system components and tokens
only, en/fr, back-office list patterns as the POS screens (ADR 008).

## Integrations

- **POS → Inventory**: stock out on sale, back on void and refund (INV-14),
  stock on the till (INV-17). Switches on only when both are active.
- **Procurement → Inventory**: `GoodsReceived` (INV-15).
- **Inventory → Accounting**: `StockMoved`, `StockAdjusted`,
  `StockCountPosted`, `TransferReceived` carry the values for stock
  journals (stage 7B).
- **Automation and webhooks**: `StockLow` is the "stock below reorder
  level" trigger of concept note 6.3 once automation reads module events.

## Open questions and decisions taken

Decisions taken while building (no owner input asked, per instructions):

1. **Variants link existing items.** Each variant is a core item (code,
   barcode and price of its own); Inventory only links it to a parent with
   attributes. Generating a variant matrix (sizes × colours) as items is
   left for later.
2. **Unit precision is by unit kind** (count: whole; others: 3 decimals),
   because core units carry no precision. Applies to typed quantities only;
   a till's sale quantity is never refused.
3. **Costs are per company and item**, pooled over the company's
   locations; the valuation by location is quantity × the company's unit
   cost. Default method: weighted average.
4. **Negative stock costing**: the shortfall is costed at the last receipt's
   unit cost (0 if none, flagged), and the receipt that covers it records
   the difference as `cost_variance_minor` for Accounting.
5. **Counts net what moved during the count**: variance = counted −
   (expected at the freeze + what moved that item and batch at the
   location since the freeze, the count's own posting left out), worked
   out at submission and again when the variance posts. Sales and goods
   received during a count are allowed (device wins, goods are there) and
   flagged `during_count`; they are never counted twice. Starting a count
   takes a lock per location that postings there take shared, so a count
   never starts in the middle of a posting and two counts never overlap.
6. **Adjustment reasons are a fixed, translated list** plus a free note;
   tenant-defined reasons can come later as data.
7. **Transfers stay within one company**; inter-company transfers wait for
   TEN-09.
8. **Batch and serial at the till**: deferred. Sales take batches FEFO and
   flag serial items `serial_not_captured`.
9. **Kits** do not move stock yet (Manufacturing will explode them).
10. **`GoodsReceived` lives in Inventory's `Contracts` namespace** so
    Procurement can dispatch it without Inventory being active; Procurement
    may move it to its own namespace (Inventory then listens to that class).
11. **POS stock lines** come from a POS-owned helper on the POS events, the
    only change to the POS module.
12. Returned goods go back to stock as sellable; a "return to damaged" path
    is an adjustment.
13. **Undoing opening stock is exact or refused.** An imported opening
    line is reversed at its own cost and layer only while nothing later
    moved the item in the company (`opening_used`, checked again under the
    item's cost lock) and only when it covered no shortfall: an opening that
    covered negative stock carries a cost variance an exact reversal cannot
    undo, so its undo is refused (409 `opening_has_variance`); the stock is
    adjusted instead.
14. **A transfer's value is what left the source.** The dispatch carries the
    company's unit cost then; on receipt, what arrived takes its share of
    that value and the loss the rest, so arrived + lost = dispatched
    exactly, whatever moved the cost in between. The company's cost goes
    down by what the loss takes from it now; the difference is the loss's
    `cost_variance_minor`.

Open for the owner:

- Should transfers between companies of one tenant be allowed (TEN-09)?
- Should storekeepers see costs (`inventory.valuation.view`) by default?
- Should counts block sales of counted items while frozen?
