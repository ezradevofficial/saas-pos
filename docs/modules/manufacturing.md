# Manufacturing module spec (phase 7D)

Source: concept note sections 3 (restaurant, quick service and bar rows:
kitchen orders made to order) and 5 (Manufacturing / production row: bills
of materials and recipes, production orders, raw material consumption,
wastage, yield, costing; works best with Inventory and POS) and the
integration rule; platform core spec (TEN-01, TEN-04, TEN-06, RBAC-01 to
RBAC-09, MD-02, MD-05, CUR-04, WF-01, WF-02, WF-10, APR-01, APR-07, CF-01,
CF-06, NUM-01, TPL-01, TPL-04, LAY-04, AUD-01, IMP-01, IMP-02, EXP-01);
ADR 002 (RLS), ADR 003 (money), ADR 008 (lists), ADR 014 (imports);
docs/modules/inventory.md (the stock ledger Manufacturing defers to),
docs/modules/stores.md (the pattern of a module that moves stock through
Inventory) and docs/modules/accounting.md (the posting contract). When this
file and the core spec disagree, the core spec wins.

## Scope

Manufacturing is a module (`api/modules/Manufacturing`, permissions
`manufacturing.*`, catalogue key `manufacturing`), sold and switched on per
tenant (RBAC-08). It covers two ways of turning materials into products:

- **Production** (made to stock): a bill of materials (BOM) says what one
  batch of a product takes; a production order plans a quantity, is
  released (approved where the company asks for it), takes materials out of
  stock (issues, in parts), reports what came out (good units, scrap,
  by-products, in parts) and is closed. Finished goods enter stock at their
  actual cost.
- **Restaurant recipes** (made to order): a recipe says what one portion of
  a menu item takes. When the till sells the menu item, its ingredients
  leave stock at once (backflush). This applies only while Manufacturing,
  Inventory and POS are all active.

Everything is scoped by company and location (RBAC-04): a BOM belongs to a
company; a production order is made at a location of that company, where
its materials are taken and its products received.

- **Items** are core items (MD-02), shared or of the company (TEN-08).
  Quantities are typed in any unit of the item (core item units) and kept
  as exact decimals, `numeric(20,6)`, with the quantity in the item's base
  unit beside them (the line's own factor, fixed when it is saved).
  Quantities travel as decimal strings.
- **Costs** are `bigint` minor units of the company's base currency (ADR
  003). They come from Inventory: Manufacturing never values stock itself.
- **Inventory on and off.** With Inventory active, Manufacturing raises
  events and Inventory moves and values the stock (Stores' pattern):
  Manufacturing never reads or writes Inventory's tables and Inventory never
  reads Manufacturing's. With Inventory off, Manufacturing works on
  quantities only: BOMs, orders, issues and reports are recorded with no
  stock moved and no costs (decision 1).

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| MFG-01 | **Stand-alone module.** Manufacturing registers through the core seams (module registry, permission catalogue, role templates, document type and default flow, number formats, document templates, custom field entities, import framework, list views, event replays) and works with only the core. Every route is behind `module:manufacturing` (403 `module_inactive`). | Must |
| MFG-02 | **Bills of materials and recipes.** A BOM belongs to a company and names an output item of the company or shared, and a kind: `production` (a made-to-stock product: the item is a stock item, `not_a_stock_item` otherwise) or `recipe` (a made-to-order menu item: the item is a `kit` or `non_stock` item, `recipe_item_stocked` otherwise, decision 3). An item has at most one active BOM per company and kind (`bom_exists`). A BOM has a name, custom fields (MFG-22) and versions; it is archived, never deleted (TEN-06). | Must |
| MFG-03 | **Versions with effective dates.** A BOM has numbered versions. A `draft` version is edited freely (one draft per BOM); publishing it fixes it (`published`, immutable) from its `effective_from`, which is strictly later than the last published version's (`effective_from_order`) and defaults to now. The version in force at a moment is the published version with the latest `effective_from` at or before it; a newer version supersedes the older from its date. A new draft may start as a copy of any version. | Must |
| MFG-04 | **Lines: components and by-products.** A version yields `output_qty` of the output item in one of its units (one batch) and takes `labour_minutes` per batch. Each line is a `component` (an item, a unit of it, a positive quantity within the unit's precision, a `yield_percent` (0 < y ≤ 100, default 100: the usable share after preparation), a `wastage_percent` (0 ≤ w ≤ 500, default 0: the expected process loss on top of the quantity), and `explode` (a phantom: the component's own BOM is exploded in its place instead of taking it from stock)) or a `by_product` (a stock item that comes out with the product, a quantity per batch and a `cost_share_percent` of the batch's cost, together below 100). Components are stock items unless exploded; the output item cannot be its own component. 1 to 200 lines. | Must |
| MFG-05 | **BOM explosion.** For a demand of D base units of the output item, a component takes `qty_base × D / output_qty_base × 100 / yield_percent × (1 + wastage_percent / 100)` base units; an exploded component's requirement becomes the demand of its own BOM version in force at the same moment (the recipe kind first for a recipe, the production kind first for a production BOM, else the other), down to items taken from stock. Exact decimals throughout, rounded once to 6 places (half up) per leaf, the same item summed over the tree. A version whose explosion would reach itself is refused at publishing (`bom_cycle`), and an explosion deeper than 10 levels is refused (`bom_too_deep`). `GET …/explode` shows the tree, the leaves and, for holders of `manufacturing.cost.view` with Inventory active, their current costs (MFG-06). | Must |
| MFG-06 | **Recipe costing.** The cost of a BOM in force now, per output unit and per batch (owner decision B, 2026-10-16: full cost against the net price): the ingredients at Inventory's current unit costs of the leaves (Inventory's `StockCosts` contract: the company's value ÷ quantity, else the last receipt's unit cost), plus labour and overhead where the company sets rates (the version's minutes, scaled to the quantity, × rate ÷ 60, half up, as MFG-16), less each by-product's `cost_share_percent` of that cost (rounded down, as MFG-16); per menu item, its price from the company's default price list in the base currency (as listed, saying whether it includes tax), the price before tax (a tax-inclusive price less the tax of the item's core tax code, MD-03; unknown without a tax category, code or rate, named by `net_price_reason`) and the cost's share of that net price. Items without a cost are named (`uncosted`). With Inventory off: not costed. Costs only for holders of `manufacturing.cost.view` (RBAC-05). | Must |
| MFG-07 | **Restaurant backflush.** When the tenant has Manufacturing and Inventory active (POS raised the sale, so it is active too), a completed POS sale consumes, for every sold line whose item has a recipe in force at the sale's time for the sale's company, the recipe's exploded ingredients for the sold quantity (in the item's base unit by the core unit factor) from the sale's location: Manufacturing records a backflush (per sale line and ingredient) and raises `MaterialsMoved` (`backflush`), and Inventory moves the stock out (`recipe_consumption`, never refused: a shortfall is flagged `negative_stock`, batches FEFO). Queued after the sale commits, so the upload never waits for it and costs no query per sale to decide (UploadQueryBudgetTest). | Must |
| MFG-08 | **Exactly once, and back on void or refund.** A sale is backflushed once (unique per source): a redelivered event consumes nothing more. A void returns everything the sale consumed and not yet returned; a refund returns its share of each refunded line's ingredients (refunded ÷ sold of each ingredient, 6 places half up; the refund that completes a line returns exactly what is left), at the cost they left at (`recipe_return`). Events in any order (queued jobs, retries) give the same result: everything about one sale runs under one lock per sale; a void or refund handled before its sale returns nothing, and the sale, handled later, consumes nothing when voided and applies the refunds already made. | Must |
| MFG-09 | **The sold item is never moved twice.** A made-to-order menu item is a `kit` or `non_stock` item (MFG-02), which Inventory's POS listener never moves (it moves stock items only, INV-14); its ingredients move through the recipe only. A recipe is refused on a stock item (`recipe_item_stocked`), and an item whose type was changed to `stock` after its recipe was published is not backflushed (its sale line is skipped and counted in the backflush report as `item_stocked`), so the item is moved by Inventory alone (decision 3). | Must |
| MFG-10 | **Production orders: plan.** An order names a location (not archived) of a company, an output item with a `production` BOM of that company, a quantity in a unit of the item, planned start and end dates, a note and custom fields. The version in force at the planned start (or one named, published, of that BOM) is fixed on the order; its explosion for the planned quantity becomes the order's material lines (item, required base quantity) and its by-products the order's expected outputs. Numbered (MFG-25), `planned`. While planned, the quantity, dates, note and custom fields can change (the materials are exploded again). | Must |
| MFG-11 | **Release, with approval where configured.** Releasing a planned order fixes its ledger (`inventory` when Inventory is active then, else `none`) and the company's labour and overhead rates on it. When the company's settings ask for release approval, the order is the workflow document `manufacturing.production_order` (WF-01), `pending_approval`, whose default flow (WF-02) is one approval by a Branch Manager at the location (APR-07: never the planner); approved: `released`; rejected: `rejected`. Otherwise it is `released` at once. | Must |
| MFG-12 | **Material issues and returns.** A released or in-progress order takes materials from its location: each issue line names a material line of the order and a positive quantity in a unit of its item; any number of issues, partial or beyond the requirement (beyond is allowed and shown as a variance, MFG-18). Unused materials come back against the lines of one issue, never more than issued less already returned (`over_return`). Issues and returns are numbered (MFG-25) and follow the order's ledger: with `inventory`, `MaterialsMoved` (`issue`, `return`) has Inventory move them (`production_issue`, never refused, flagged `negative_stock`; `production_return` at the cost the issue went out at). The first issue or report makes the order `in_progress`. | Must |
| MFG-13 | **Production reports, partial completion.** A report records good quantity (in the order's unit), scrap quantity with a reason (`damaged`, `quality`, `process`, `other`), by-product quantities (expected outputs of the order) and labour minutes (default: the version's minutes for the good quantity, half up to whole minutes). Any number of reports until the order is closed; good output beyond the planned quantity is allowed (shown in the yield report). With `inventory`, the good output and by-products enter stock at their cost (MFG-16) through `MaterialsMoved` (`receipt`, `production_receipt` with a cost layer); scrap never enters stock. Numbered (MFG-25). | Must |
| MFG-14 | **Close and cancel.** A released or in-progress order is closed (`closed`, with a note), whatever it produced (a short close); closing waits until every issue, return and report of the order is valued (409 `valuation_pending`). A planned, pending or released order with nothing issued or reported is cancelled with a reason (its flow cancelled). | Must |
| MFG-15 | **Inventory integration.** Manufacturing's `MaterialsMoved` (after commit) carries the document, location, time, kind and lines (item, unit, quantity and base quantity; a return names the lines it gives back; a receipt its line costs). Inventory's listener, in its own module, posts it through its ledger with source types `manufacturing.issue`, `manufacturing.return`, `manufacturing.receipt`, `manufacturing.backflush`, `manufacturing.backflush_return` and the movement kinds `production_issue`, `production_return`, `production_receipt`, `recipe_consumption`, `recipe_return` (inserted into `inventory_movement_kinds` by Manufacturing's migration), once per document (the ledger's idempotency), whatever the module's state when its job runs (the document's ledger was fixed while Inventory was active). `StockMoved` carries each movement's value and cost variance back; Manufacturing records them on its lines once (`valued_at`), and a redelivered posting raises `StockMoved` again so a value missed once is recorded later. A report's receipt posts what Inventory received of each output (stage 7D closing review): an output Inventory took nothing in for (an item no longer stocked) is valued at nothing, flagged `not_received` on the report (shown on the order), and its cost stays in WIP until the close clears it to the variance. | Must |
| MFG-16 | **Costing.** Actual cost: materials at Inventory's value of what was issued less what was returned, plus labour (`minutes × labour_rate_minor ÷ 60`) and overhead (`minutes × overhead_rate_minor ÷ 60`) at the company's configured rates (company settings, not statutory; half up to minor units), fixed on the order when it is released. A report is costed once every issue and return of its order is valued: it takes the share `good ÷ (planned − good reported before)` of the materials not yet relieved (all of them when it reaches the planned quantity; none when that is zero or less), half up, plus its own labour and overhead. By-products take `cost_share_percent` of the report's cost each (rounded down); the good output takes the rest exactly. Scrap takes no cost: normal loss stays in the good units. At close, what is left in WIP (materials + labour + overhead − relieved) is the order's variance. All amounts are integers in minor units; shares always add up to the whole. | Must |
| MFG-17 | **Without Inventory.** An order released while Inventory is off has the ledger `none`: issues, returns and reports record quantities only, nothing is costed, nothing is posted; the consumption and yield reports show quantities and "not valued". Recipes do not backflush. Switching Inventory on later does not change orders already released (decision 1). | Must |
| MFG-18 | **Consumption: actual against standard.** Per order and material: the standard quantity for the good output reported (`required × good ÷ planned`, 6 places half up), the actual quantity (issued − returned), the difference and its share, and (valued orders, `manufacturing.cost.view`) the actual value, the standard value at the actual unit cost, and the difference. Filters: company, location, item, order, dates (the order's release), status. Export (EXP-01). | Must |
| MFG-19 | **Wastage and yield.** Per order: planned, good, scrap and by-product quantities, yield (`good ÷ planned`), scrap share (`scrap ÷ (good + scrap)`), in percent with one place (the expected wastage of each material for the output is in the consumption report, MFG-18), and (valued, with the permission) material, labour, overhead, relieved and variance amounts. Filters and export as MFG-18. | Must |
| MFG-20 | **Accounting events.** `ProductionValued` (after commit, once per document) carries what Accounting needs: `materials` (an issue or a return: per line its value and Inventory's cost variance), `production` (a report: the received lines' cost and cost variance, the labour and overhead it absorbed), `variance` (an order closed with WIP left), `backflush` (a sale's ingredients consumed or returned). Accounting's listener (in the Accounting module) posts them through its rules, idempotently by document, balanced (ACC-35); Inventory's `StockMoved` for `manufacturing.*` sources posts nothing (the same movement). Valued documents are raised again by the Accounting backfill (ACC-29, core EventReplays). | Must |
| MFG-21 | **Scope and costs.** Every list, document and action is scoped by the order's location, a BOM, the company's settings and recipe costing by its company, reached by a holder of the permission anywhere in it (RBAC-04): a record out of the reader's places is not found. Costs and values are shown only to holders of `manufacturing.cost.view` at the place (RBAC-05) and never exported to others. | Must |
| MFG-22 | **Custom fields** (CF-01, CF-06): BOMs (`manufacturing_bom`) and production orders (`manufacturing_order`) carry custom fields in a `custom` jsonb column (GIN indexed), offered only while the module is active; the order's print shows them. | Must |
| MFG-23 | **BOM import** (IMP-01, IMP-02). Importer `bom_lines`: one row per line: company, output item code, kind, output quantity and unit, labour minutes, line kind, component item code, quantity, unit, yield, wastage, explode, cost share. Rows go into the item's draft version (the BOM and draft are made when missing); publishing stays a person's act. Undo removes the imported line from a version still draft; a published line cannot be undone (`bom_published`). | Must |
| MFG-24 | **Printed documents** (TPL-01, TPL-04): the production order (`manufacturing.production_order`: order, product, quantities, dates, materials required, custom fields, signature) and the pick list (`manufacturing.pick_list`: materials to pick at the location: item, code, unit, required, issued, still to issue). HTML for printing or PDF; black on white in every theme; no values. | Must |
| MFG-25 | **Numbering** (NUM-01): orders `MO-{BRANCH}-{YYYY}-{00001}`, issues `MI-{BRANCH}-{YYYY}-{00001}`, returns `MR-{BRANCH}-{YYYY}-{00001}`, reports `PR-{BRANCH}-{YYYY}-{00001}`, yearly. | Must |
| MFG-26 | **Audit** (AUD-01): BOMs (create, change, archive), versions (create, edit, publish), settings, and every order's create, change, release, decision, issue, return, report, close and cancel. | Must |
| MFG-27 | **Back-office lists** (EXP-01, LAY-04): BOMs, orders, consumption, yield and variances, backflushes and recipe costing, with search, filters, sort, saved views and export. | Must |
| MFG-28 | **Events raised** (after commit): `MaterialsMoved` (MFG-15), `ProductionValued` (MFG-20), `ProductionOrderReleased`, `ProductionReported` and `ProductionOrderClosed` (ids and quantities, for webhooks and automation). | Must |
| MFG-29 | **Settings per company**: `labour_rate_minor` and `overhead_rate_minor` per labour hour in the base currency, and `release_approval`. `manufacturing.settings.edit` at the company; audited. | Must |
| MFG-30 | **Recipe availability at the till.** The till could show how many portions of a menu item its location's stock allows. Not built: a follow-up for the POS module (decision 7). | Could |

## Data model

Every table has `tenant_id` under forced RLS (TEN-01) and UUID v7 keys.
Quantities `numeric(20,6)`; percentages `numeric(7,4)`; money `bigint`
minor units with a `currency char(3)` (the company's base currency, from
Inventory).

| Table | Holds |
| --- | --- |
| `mfg_settings` | Per company: `labour_rate_minor`, `overhead_rate_minor`, `currency`, `release_approval` |
| `mfg_boms` | Company, output item, `kind` (`production`, `recipe`), name, `custom` (jsonb, GIN), creator, `archived_at`; one active per company, item and kind |
| `mfg_bom_versions` | BOM, `version_no`, `status` (`draft`, `published`), `effective_from`, `output_qty`, `output_uom_id`, `output_qty_base`, `labour_minutes`, note, publisher and time; one draft per BOM |
| `mfg_bom_lines` | Version, line no, `kind` (`component`, `by_product`), item, unit, `qty`, `qty_base`, `yield_percent`, `wastage_percent`, `explode`, `cost_share_percent`, note, `import_id` |
| `mfg_orders` | Number, company, branch, location, item, BOM and version, `qty`, unit, `qty_base`, planned start and end, status (`planned`, `pending_approval`, `released`, `in_progress`, `closed`, `cancelled`, `rejected`), `ledger` (`inventory`, `none`, null until released), rates and currency, `qty_good_base`, `qty_scrap_base`, material, labour, overhead, relieved and variance amounts, `custom`, people and times |
| `mfg_order_materials` | Order, line no, item, `qty_required_base`, `qty_issued_base`, `qty_returned_base`, `value_issued_minor`, `value_returned_minor`, `expected_loss_base` (the explosion's wastage and yield loss) |
| `mfg_order_outputs` | Order, line no, item, `kind` (`product`, `by_product`), `qty_planned_base`, `qty_reported_base`, `cost_share_percent` |
| `mfg_issues`, `mfg_issue_lines` | Number, order, company, branch, location, `ledger`, issuer and `issued_at`, note, `valued_at`, `currency`; lines with the material line, item, unit, `qty`, `qty_base`, `qty_returned_base`, `value_minor`, `cost_variance_minor` |
| `mfg_returns`, `mfg_return_lines` | Number, order, issue, company, branch, location, `ledger`, reason, note, who and `returned_at`, `valued_at`, `currency`; lines with the issue line, item, unit, `qty`, `qty_base`, `value_minor`, `cost_variance_minor` |
| `mfg_reports`, `mfg_report_lines` | Number, order, company, branch, location, `ledger`, `qty_good`, `qty_good_base`, `qty_scrap`, `qty_scrap_base`, scrap reason, `labour_minutes`, material, labour, overhead and total cost, status (`recorded`, `pending`, `costed`, `received`), who and `reported_at`, `costed_at`, `received_at`, `currency`; lines with the output, `kind` (`product`, `by_product`, `scrap`), item, `qty_base`, `cost_minor`, `cost_variance_minor` |
| `mfg_backflushes`, `mfg_backflush_lines` | Source type (`pos.sale`, `pos.void`, `pos.refund`) and id (unique), the sale, `kind` (`consume`, `return`), company, branch, location, reference, `occurred_at`, `valued_at`, `currency`, `skipped` (sale lines not backflushed and why); lines with the sale line, sold item, BOM version, ingredient item, `qty_base`, `qty_returned_base`, the consumed line a return gives back, `value_minor`, `cost_variance_minor` |

## Events

**Raised** (`Modules\Manufacturing\Events`, dispatched after commit, ids,
quantities and values only):

| Event | When | Carries |
| --- | --- | --- |
| `MaterialsMoved` | An issue, return or report of an `inventory`-ledger order is saved (a report once costed); a sale is backflushed or its void or refund returns ingredients | tenant, `kind` (`issue`, `return`, `receipt`, `backflush`, `backflush_return`), document id and number, the document whose movements a return reverses (`origin_id`), company, branch, location, time, user, lines `[{line_id, item_id, uom_id, qty, qty_base, origin_line_id?, cost_minor?}]` |
| `ProductionValued` | Inventory valued an issue, return, report receipt or backflush; an order closed with WIP left | tenant, `kind` (`materials`, `production`, `variance`, `backflush`), document id and number, order id and number, company, branch, location, time, currency, lines `[{line_id, item_id, value_minor, cost_variance_minor}]` (signed as stock movements: out negative), `labour_minor`, `overhead_minor`, `variance_minor` |
| `ProductionOrderReleased`, `ProductionReported`, `ProductionOrderClosed` | The order is released, a report saved, the order closed | tenant, order id and number, company, location, item, quantities |

**For Accounting (ACC-35).** Each `ProductionValued` is one journal, every
line on the debit side with signed amounts (as Inventory's sources):

| Source type | Fields | Default rule (KE; CD) |
| --- | --- | --- |
| `manufacturing.materials` | `stock` (value − cost variance), `wip` (−value), `cost_variance` | stock 1300, work in progress 1310, cost of goods sold 5100 (CD: 311, 341, 603) |
| `manufacturing.production` | `stock` (received value − cost variance), `wip` (−received + labour + overhead), `labour` (−labour), `overhead` (−overhead), `cost_variance` | 1300, 1310, labour absorbed 5410, overhead absorbed 5420, 5100 (CD: 311, 341, 781, 781, 603) |
| `manufacturing.variance` | `wip` (−variance), `variance` | 1310, production variances 5400 (CD: 341, 658) |
| `manufacturing.backflush` | `stock` (value − cost variance), `cost_of_sales` (−value), `cost_variance` | 1300, 5100, 5100 (CD: 311, 603, 603) |

**Consumed:**

| Event | From | Effect |
| --- | --- | --- |
| `SaleCompleted`, `SaleVoided`, `SaleRefunded` | POS | backflush (MFG-07, MFG-08), lines read through POS's `SaleStockLines` |
| `StockMoved` (sources `manufacturing.*`) | Inventory | record values, cost pending reports, raise `ProductionValued` |
| `WorkflowCompleted`, `WorkflowCancelled` | Core | settle orders waiting for release approval |

**Inventory side (the minimal change to Inventory).** Inventory listens to
`MaterialsMoved` (`MoveStockForManufacturing`, a queued job with the
event's lines, never reading Manufacturing's tables) and posts it with the
source types and kinds above; outflows are never refused and take batches
earliest expiry first; returns come back from the origin's movements at
their cost, the last units taking what is left of the value exactly; a
receipt is a costed inbound line (a cost layer, the item's last cost). A
return handled before its origin is retried by its job. Inventory also
offers the read contract `Contracts\StockCosts` (current unit cost per item
and company) for recipe costing, as POS offers `SaleStockLines`. Its POS
listener is unchanged: it never moved non-stock items.

## Permissions

| Permission | What it allows |
| --- | --- |
| `manufacturing.bom.view` | BOMs, versions, explosions |
| `manufacturing.bom.manage` | Create, edit, publish and archive BOMs and versions; import BOMs |
| `manufacturing.order.view` | Production orders at the place, their documents and prints |
| `manufacturing.order.create` | Plan and change planned orders |
| `manufacturing.order.approve` | Act on the release flow (WF-08) |
| `manufacturing.order.release` | Release a planned order |
| `manufacturing.order.close` | Close a released or in-progress order |
| `manufacturing.order.cancel` | Cancel an order with nothing issued or reported |
| `manufacturing.material.issue` | Issue materials to an order |
| `manufacturing.material.return` | Return unused materials |
| `manufacturing.production.report` | Report production (good, scrap, by-products, labour) |
| `manufacturing.report.view` | Consumption, yield and variance reports, backflushes, recipe costing |
| `manufacturing.cost.view` | Costs and values everywhere in Manufacturing (RBAC-05) |
| `manufacturing.settings.edit` | A company's rates and release approval; move and value a stuck document again |

Role templates (RBAC-03): **Owner** everything; **Admin**
`manufacturing.*.view`, `manufacturing.settings.edit` and
`manufacturing.bom.manage`; **Branch Manager** `manufacturing.*` except
`manufacturing.bom.manage` and `manufacturing.settings.edit` (they view
and explode BOMs; changing them is company data, held at company scope:
least privilege, review fix 3);
**Storekeeper** issues, returns and the views of BOMs and orders (no
costs); **Accountant** `manufacturing.*.view` (with costs); **Approver**
`manufacturing.order.approve` and `manufacturing.order.view`;
**Read-only Auditor** `*.view`.

## API

Prefix `/api/v1`, people's tokens, every route behind
`module:manufacturing` (403 `module_inactive`). Lists follow ADR 008
(`?search`, `?sort`, `?per_page`, `?page`, `?format=csv|xlsx|pdf`,
`?columns[]`, `?company`, `?branch`, `?location`, `?from`, `?to`,
`?status`). Quantities are decimal strings; money is `{amount_minor,
currency}`; percentages are decimal strings.

| Route | Permission | Notes |
| --- | --- | --- |
| `GET manufacturing/status` | any `manufacturing.*.view` | `{inventory_active, pos_active, backflush_active}` |
| `GET manufacturing/companies/{company}/settings`, `PUT …` | `bom.view` or `order.view`; `settings.edit` at the company | `{labour_rate_minor, overhead_rate_minor, currency, release_approval}`; the rates are null unless the reader holds `cost.view` or `settings.edit` in the company |
| `POST manufacturing/revalue` | `settings.edit` at the document's company | `{kind: issue\|return\|report\|backflush, document_id}` raises the stuck document's stock move again (202); 409 `nothing_to_revalue` when it is valued; audited `manufacturing.document.revalue` |
| `GET manufacturing/boms`, `GET …/{mfg_bom}` | `bom.view` | `?kind`, `?item`, `?status=active\|archived\|all`; detail has versions and the version in force |
| `POST manufacturing/boms` | `bom.manage` at the company | `{company_id, item_id, kind, name?, custom?, version: {output_qty, output_uom_id?, labour_minutes?, note?, lines: [...]}}` makes the BOM and its draft version 1 |
| `PUT manufacturing/boms/{mfg_bom}` | `bom.manage` | `{name?, custom?}` |
| `POST manufacturing/boms/{mfg_bom}/archive` | `bom.manage` at the company | |
| `POST manufacturing/boms/{mfg_bom}/versions` | `bom.manage` at the company | `{copy_from_id?}` a new draft (409 `draft_exists`) |
| `GET manufacturing/bom-versions/{mfg_bom_version}` | `bom.view` | lines |
| `PUT manufacturing/bom-versions/{mfg_bom_version}` | `bom.manage` | draft only (409 `version_published`): `{output_qty, output_uom_id?, labour_minutes?, note?, lines: [{kind, item_id, uom_id?, qty, yield_percent?, wastage_percent?, explode?, cost_share_percent?, note?}]}` |
| `POST manufacturing/bom-versions/{mfg_bom_version}/publish` | `bom.manage` at the company | `{effective_from?}` |
| `GET manufacturing/bom-versions/{mfg_bom_version}/explode` | `bom.view` | `?qty`, `?uom_id`, `?at`: `{tree, leaves, cost?}` |
| `GET manufacturing/orders`, `GET …/{mfg_order}` | `order.view` | `?item`; detail has materials, outputs, issues, returns, reports, costs (with the permission), the flow and what the reader may do |
| `POST manufacturing/orders` | `order.create` at the location | `{location_id, item_id, bom_version_id?, qty, uom_id?, planned_start?, planned_end?, note?, custom?}` |
| `PUT manufacturing/orders/{mfg_order}` | `order.create` | planned only: `{qty?, uom_id?, planned_start?, planned_end?, note?, custom?}` |
| `POST manufacturing/orders/{mfg_order}/release` | `order.release` | |
| `POST manufacturing/orders/{mfg_order}/cancel` | `order.cancel` | `{reason}` |
| `POST manufacturing/orders/{mfg_order}/close` | `order.close` | `{note?}` |
| `POST manufacturing/orders/{mfg_order}/issues` | `material.issue` at the location | `{note?, lines: [{material_id, qty, uom_id?}]}` |
| `POST manufacturing/orders/{mfg_order}/returns` | `material.return` at the location | `{reason, note?, lines: [{issue_line_id, qty}]}` |
| `POST manufacturing/orders/{mfg_order}/reports` | `production.report` at the location | `{qty_good, qty_scrap?, scrap_reason?, labour_minutes?, note?, by_products?: [{output_id, qty}]}` |
| `GET manufacturing/orders/{mfg_order}/print` | `order.view` | `?document=production_order\|pick_list`, `?format=html\|pdf` |
| `GET manufacturing/consumption` | `report.view` | MFG-18 |
| `GET manufacturing/yield` | `report.view` | MFG-19 |
| `GET manufacturing/backflushes` | `report.view` | one row per backflush: sale reference, kind, lines, values |
| `GET manufacturing/recipe-costing` | `report.view` | MFG-06: one row per BOM in force; `?kind`, `?item` |

Errors: `not_a_stock_item`, `recipe_item_stocked`, `bom_exists`,
`item_other_company`, `uom_not_on_item`, `qty_invalid`, `qty_precision`,
`component_is_output`, `cost_share_total`, `version_published`,
`draft_exists`, `effective_from_order`, `bom_cycle`, `bom_too_deep`,
`no_bom_in_force`, `version_other_bom`, `location_archived`,
`location_unknown`, `document_not_open`, `line_unknown`, `over_return`,
`output_unknown`, `valuation_pending`, `bom_published`.

## Screens (web)

Under **Manufacturing** in the sidebar: BOMs and recipes (list; detail with
a tree editor of the draft version, versions with their dates, publish,
copy to a new draft, explosion), Production orders (list, plan, detail with
release, issue, return, report, close, cancel and prints), Consumption
(actual against standard), Yield and variances, Backflushes, Recipe costing
(cost per menu item from current Inventory costs), and the company settings
(rates, release approval). Design-system components and tokens only, en/fr,
back-office list patterns as the Stores screens (ADR 008).

## Integrations

- **POS → Manufacturing → Inventory**: restaurant backflush (MFG-07 to
  MFG-09). Switches on only when the three modules are active.
- **Manufacturing → Inventory**: `MaterialsMoved` (MFG-15); values come
  back through `StockMoved`; recipe costing reads `StockCosts` (MFG-06).
- **Manufacturing → Accounting**: `ProductionValued` (MFG-20, ACC-35).

## Open questions and decisions taken

Decisions taken while building (no owner input asked, per instructions):

1. **Without Inventory, quantities only.** Manufacturing keeps no stock
   ledger and no costs of its own; an order released while Inventory is off
   stays quantity-only (its ledger `none`) even if Inventory is switched on
   before it is closed, and one released with Inventory keeps posting
   through it (its ledger `inventory`). The ledger is fixed per order at
   release, so an order is never half valued.
2. **One location per order**: materials are issued from and products
   received at the order's location. Moving materials to the production
   floor first is an Inventory transfer.
3. **Made to order means not stocked.** A recipe's menu item is a `kit` or
   `non_stock` item; Inventory's POS listener already moves stock items
   only, so the sold item and its ingredients never both move, without
   Inventory reading Manufacturing's data or the two jobs depending on each
   other's order. An item made in batches ahead (a cake sold by the slice)
   is a stock item with a `production` BOM. If a menu item's type is
   changed to `stock` later, Manufacturing skips it (`item_stocked`); the
   only window left is a type change between the two queued jobs of one
   sale, which moves the item once by Inventory and once by its recipe or
   neither (seconds; the backflush report shows the skip).
4. **Costing is actual and pooled per order.** Materials at Inventory's
   value; a report relieves its share of the material not yet relieved
   (good ÷ planned still open) plus its own labour and overhead; the last
   report (reaching the planned quantity) takes the rest; what is left at
   close is the variance. Scrap absorbs no cost (normal loss);
   by-products take their configured share of each report's cost.
5. **Labour and overhead are company rates per labour hour**, typed by the
   company (settings), not statutory data; minutes per batch come from the
   version, actual minutes from the report.
6. **Release approval is a company setting**: off by default (released at
   once); on, the release runs the `manufacturing.production_order` flow,
   which the tenant may redesign (WF).
7. **No till changes.** Recipe availability ("12 portions left") at the
   till is a follow-up (MFG-30): it needs the till to hold recipes and
   stock per ingredient.
8. **Backflush applies the recipe in force when the sale happened** (its
   `sold_at`), so offline sales uploaded days later use the recipe of their
   day; a refund or void gives back what the sale took, whatever the recipe
   is now. The version is looked up across every BOM of the item and kind
   that was active then (`effective_from` at or before the sale, and not
   archived or archived after it), phantoms too: a recipe archived or
   replaced after the sale still applies to it (review fix 2).
9. **Accounting posts Manufacturing's documents from `ProductionValued`**
   and skips `StockMoved` for `manufacturing.*` sources, as for Stores: one
   journal per document, with work in progress between issue and receipt.
   `ProductionValued` is queued on Accounting alone (the stage 7B
   decision): a document made while Manufacturing was on is valued when
   Inventory moves it, possibly after Manufacturing is switched off, and the
   ledger follows the stock (review fix 6).
10. **Components may be over-issued** (actual beyond standard is shown as
    a variance, not refused): the goods have physically left the store.

11. **A draft's lines are replaced when it is saved** (audited with the
    lines before and after, `manufacturing.bom_version.lines_update`); a
    published version's lines never change. Draft lines are configuration
    being edited, not business records.
12. **BOMs are company data.** A BOM, the company's settings and recipe
    costing are reached by a holder of the permission anywhere in the
    company (a branch manager keeps the BOMs of their company); orders and
    their documents by their location.
13. **By-products are the top level's.** An exploded (phantom) component's
    own by-products are not carried into the order.
14. **A return gives back lines of one issue**, so Inventory takes its cost
    back from that issue's movements.
15. **Nothing moved is nothing valued.** When Inventory moves none of a
    document's lines (an item no longer a stock item), it still raises an
    empty `StockMoved`, so the document is valued at nothing and its order
    can close. A redelivered document that already moved raises its own
    movements again, never nothing (review fix 1).
16. **A return never waits forever.** A return whose origin is not posted
    yet is retried for 24 hours (`retryUntil`). When the origin posted but
    holds nothing for a stock line, that line comes back at nothing and is
    flagged `nothing_to_return` on the return or backflush return. A
    document whose job gave up anyway is moved and valued again by a
    holder of `manufacturing.settings.edit` (`POST manufacturing/revalue`);
    Inventory posts once and Manufacturing values once, so nothing counts
    twice (review fix 5).

17. **Recipe costing is full cost against the price before tax** (owner
    decision B, 2026-10-16; MFG-06): ingredients, plus labour and
    overhead at the company's rates, less the by-products' credit, as a
    share of the net price; a tax-inclusive price is netted with the
    item's core tax code, and a price whose tax is unknown shows no share.
18. **A receipt posts what Inventory received** (stage 7D closing review):
    each output line keeps Inventory's received value; an output it took
    nothing in for is valued at nothing and flagged `not_received`, its
    cost left in WIP for the close.
19. **Lists load per page**: the BOM list batches its versions in force
    and drafts, the order list its releasers and closers. Accounting's
    integration with Manufacturing is numbered ACC-35 (it shared ACC-33
    with Payroll).

Open for the owner:

- Should a production order be able to raise a Stores requisition or an
  Inventory transfer for its materials?
- Should scrap above the version's expected wastage be expensed at once
  (abnormal loss) instead of staying in the good units?
- Should production run on routings (work centres, operations, machine
  hours) instead of one labour time per batch?

## Requirement status

| Requirements | Status |
| --- | --- |
| MFG-01 to MFG-29 | Built and tested (see the module's tests) |
| MFG-30 | Follow-up (POS) |
