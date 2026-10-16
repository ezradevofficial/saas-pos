# Fixed assets module spec (phase 7, stage 7D)

Source: concept note section 5 (the Fixed assets row: asset register,
categories, depreciation methods, transfers, disposals, maintenance
schedules; integrates with Accounting and Procurement) and its pricing row
(per company); platform core spec (TEN-01, TEN-06, RBAC-01 to RBAC-09,
MD-01, MD-02, MD-05, CUR-02 to CUR-04, WF-01, WF-02, WF-10, APR-07,
NUM-01, TPL-01, TPL-04, CF-01 to CF-06, LAY-01, LAY-04, IMP-01 to IMP-03,
EXP-01, NOT-02, AUD-01 to AUD-03, CP-01, CP-02); ADR 002 (RLS), ADR 003
(money), ADR 007 (country packs), ADR 008 (lists), ADR 014 (imports).
Built on the Accounting spec (ACC-07 source contract, ACC-08 closed
periods, ACC-24 posting rules by key) and the Procurement spec (PRC-14,
PRC-15, PRC-25 events). When this file and the core spec disagree, the
core spec wins.

## Scope

Fixed assets is a module (`api/modules/FixedAssets`, permissions
`fixed_assets.*`, catalogue key `fixed_assets`, already sold per company by
Phase 6), switched on per tenant (RBAC-08). It works with only the core
installed: it reads core companies, branches and locations (TEN-02 to
TEN-04), users, parties (suppliers and buyers, MD-01), items (MD-02),
departments and cost centres (MD-05), currencies and rates (CUR-), and
keeps every fixed-asset fact in its own `fa_*` tables. It never reads or
writes another module's tables:

- **Accounting** hears about capitalisations, depreciation, revaluations,
  impairments and disposals through events and posts them through its own
  posting rules (Integrations). Fixed assets asks Accounting only one
  question, through Accounting's read contract `ClosedPeriods`: up to which
  day the company's books are closed.
- **Procurement** tells Fixed assets about goods received and supplier
  invoices through its events; Fixed assets' own listener creates draft
  assets for items flagged as assets.
- **HR** answers who an employee custodian is through its read contract
  `EmployeeDirectory`, only while HR is active.

Book depreciation is the module's job. The **tax book** (wear-and-tear
allowances) is optional, computed from country-pack data, and refused until
that data is confirmed (FA-20). Rates are never invented.

- **Money** is `bigint` minor units with its currency (ADR 003): the
  acquisition in any active currency with its rate snapshot (`numeric(18,8)`,
  CUR-04) and base amounts; every book value (cost, accumulated
  depreciation, impairment, revaluation surplus, residual value) is in the
  company's base currency, locked on first use (`BaseCurrencyLock`, CUR-02).
- **Arithmetic** is integer: every charge is computed in minor units with
  half-up rounding on exact fractions, and the last charge of a life is the
  exact remainder, so an asset's charges always add up to its cost less its
  residual value to the minor unit.
- **Dates** are calendar days in the company's time zone.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| FA-01 | **Settings per company.** The pro-rata convention of depreciation (`days`: by days in service, default; `mid_month`: half a month in the month an asset starts and in the month it is disposed, whole months between) and whether the tax book is on (FA-20). Kept in `fa_settings`, edited with `fixed_assets.settings.edit`. The convention cannot change once a run is posted for the company (`convention_locked`). | Must |
| FA-02 | **Categories.** Per company: code (unique), name, default depreciation method (`straight_line`, `declining_balance`, `units_of_production`, `none` for land), useful life in months (required except for `none` and `units_of_production`), residual value as a percent of cost (0 to 100), the declining-balance annual rate (percent, required for that method), and optionally a tax class of the company's country pack (FA-20). Categories are archived, never deleted (TEN-06); an archived category takes no new asset. | Must |
| FA-03 | **GL accounts through posting rules.** A category names no account. Every amount Fixed assets reports is keyed by category (`cost.{category id}`, `accumulated.{category id}`, `depreciation.{category id}`), so the Accounting posting rule of each event type maps a category to its own accounts (ACC-24 maps) and falls back to the rule's default account. The category screen shows which accounts each event type uses for it and links to the rule. | Must |
| FA-04 | **Asset register.** An asset of one company: tag (its number, FA-27), name, description, category, location (a core location, giving the branch), custodian (a user of the tenant, or an active HR employee of the company through HR's read contract while the HR module is active, not Payroll alone; never both), cost centre and department (MD-05, of the company), serial number, acquisition date, in-service date (depreciation starts here; defaults to the acquisition date), acquisition cost in minor units with its currency and rate (CUR-04), supplier (a party with the supplier role) and their reference, warranty end date and note, and the depreciation terms copied from the category and editable per asset: method, useful life, residual value (minor units, base), declining rate, estimated total units and the meter reading at acquisition (units of production). Statuses: `draft`, `active`, `disposed`. A draft is edited freely and can be cancelled (archived). | Must |
| FA-05 | **Activation (capitalisation).** A draft becomes `active` when activated: the category, in-service date and a cost above zero are required, the base cost is frozen at the acquisition date's rate, and the capitalisation is posted (FA-22) unless the asset is part of an opening register (FA-24). | Must |
| FA-06 | **Documents.** Files attached to an asset (invoice scans, warranty cards, photos): PDF, images, text, office documents, up to 10 MB each, stored on the `media` disk under the tenant's path, downloaded only by holders of `fixed_assets.asset.view` at the asset's place, archived (never deleted). Each upload and archive is audited. | Must |
| FA-07 | **Straight-line depreciation.** Each charge is the remaining depreciable amount (net book value less residual value) times the units of the charge's span over the units left in the asset's life from the span's start: days in service (`days`) or half months (`mid_month`). The span that reaches the end of the life takes the exact remainder. | Must |
| FA-08 | **Declining-balance depreciation.** Each charge is the net book value times the annual rate times the span's share of a year (days over the days of that year, or half months over 24), but never less than the straight-line charge of the same span (the usual switch to straight line, so the asset reaches its residual value exactly at the end of its life) and never more than the remaining depreciable amount. | Must |
| FA-09 | **Units-of-production depreciation.** An asset with estimated total units takes meter readings (FA-18). A span's charge is the remaining depreciable amount times the units used in the span (the last reading on or before its end less the last on or before its start, or the meter at acquisition) over the units still left; reaching the estimated total takes the exact remainder. A span with no reading charges nothing. | Must |
| FA-10 | **None.** Assets of method `none` (land) never depreciate. | Must |
| FA-11 | **Change of estimate.** The method, useful life, residual value, declining rate and estimated units of an active asset may change (`fixed_assets.asset.manage`, with a reason). The change is prospective (IAS 8): every method works from the net book value and the life left, so the next charge uses the new estimate and nothing posted changes. The change is kept in the asset's history with the values before and after. | Must |
| FA-12 | **Depreciation runs.** One run per company per month. **Preview** lists each active asset's charge for the month (from the day after its last depreciation, or its in-service date, to the month's end, so an asset activated late catches up) without saving anything. **Post** (`fixed_assets.depreciation.post`) saves the run and its lines, adds each charge to the asset's accumulated depreciation, moves its depreciated-to date, and posts one journal per branch and cost centre (FA-22). Runs post in order: the first run of a company is any month; each later run is the month after the last posted run (`run_out_of_order`). Posting a month twice is refused (`run_exists`, a unique index per company and month), so a retried request never doubles a charge. A month the books have closed (Accounting's closed periods) is refused (`period_closed`), as is a month not yet started in the company's time zone (`period_future`) and a month while disposals dated in it or before are pending or approved (`disposals_open`, listing their numbers: they charge their own last depreciation). Posting takes the company's run lock, which disposal posting, revaluations, impairments and changes of estimate also take; each charge is worked out again once the asset's row is locked, and an asset no longer active is left out. Reversal re-reads the run's status under the same lock. | Must |
| FA-13 | **Run reversal.** The latest posted run of a company may be reversed (`fixed_assets.depreciation.post`, with a reason) while its month is open in Accounting and no revaluation, impairment or disposal of its assets is dated on or after its month's start: accumulated depreciation and depreciated-to dates go back, and its journals are reversed (a reversing source per journal). | Should |
| FA-14 | **Transfers with approval.** A transfer moves an active asset to another location of its company, another custodian, another cost centre, or any of them, on a date, with a reason (`fixed_assets.transfer.request` at the asset's location). It is numbered and runs through the `fixed_assets.transfer` flow (WF-01; default WF-02: one approval by a Branch Manager at the asset's branch, escalated to the Admins after two business days; APR-07 never the requester). Approved, it applies at once: the asset's place, custodian and cost centre change and the history records the move. Rejected or cancelled, nothing changes. One open transfer per asset (`transfer_pending`). Transfers post no journal: depreciation after the move carries the new branch and cost centre. | Must |
| FA-15 | **Revaluation.** An active asset may be revalued to a fair value on a date (`fixed_assets.revaluation.post`). The accumulated depreciation and impairment are eliminated against the cost, which becomes the fair value. Depreciation not yet charged up to the date is charged and posted first (by days to the date; under `mid_month` to the end of the month before). An increase first reverses revaluation and impairment losses this asset took in profit or loss, the rest goes to its revaluation surplus; a decrease first uses its surplus, the rest is a loss. Depreciation goes on from the new value over the life left. Posted to Accounting (FA-22). | Must |
| FA-16 | **Impairment.** An active asset whose recoverable amount is below its net book value is impaired on a date (`fixed_assets.revaluation.post`): depreciation not yet charged is posted first (as FA-15), then the difference is added to its impairment, first against its revaluation surplus, the rest in profit or loss, which a later upward revaluation reverses through profit or loss first. Depreciation goes on from the reduced value. Posted to Accounting. | Must |
| FA-17 | **Disposals with approval.** A disposal of an active asset is a `sale` (proceeds in any active currency, rate CUR-04, optional buyer party), `scrap` or `write_off` (no proceeds), on a date, with a reason (`fixed_assets.disposal.request`). It is numbered and runs through the `fixed_assets.disposal` flow (default WF-02 as transfers). Approved, it posts: depreciation from the day after the last charge to the disposal date (pro rata by the company's convention; `mid_month` takes half the disposal month), then the gain or loss (base proceeds less net book value), the release of any revaluation surplus to retained earnings, and the asset becomes `disposed` (FA-22). The disposal date must be after the asset's depreciated-to date (`period_depreciated`) and in an open period (`period_closed`); a disposal approved while its date is refused stays `approved` with the reason and posts again when asked (`POST …/post`). | Must |
| FA-18 | **Meter readings.** Readings of an asset's meter (hours, kilometres, units) with a date and a value that never decreases (`reading_decreases`), dated after the asset's depreciated-to date (`reading_depreciated`: a charged span keeps the meter it used). They feed units-of-production depreciation (FA-09) and usage-based maintenance (FA-19). | Must |
| FA-19 | **Maintenance.** Schedules per asset, by time (every N days, from the last done date or the in-service date) or by usage (every N units of the meter), with days of warning before a time-based due date. Work orders per asset (from a schedule or not): title, description, status (`open`, `in_progress`, `done`, `cancelled`), due date, assignee (a user), vendor (a party), completion date, meter reading, and the cost in minor units of any active currency with its base amount. Completing a work order of a schedule moves the schedule's next due date or meter. Work order costs are kept for the asset's history and reports; they post no journal (the vendor's bill is an Accounting supplier invoice). A daily command (`fixed-assets:maintenance-reminders`) tells the holders of `fixed_assets.maintenance.manage` at the asset's place, once per due date, through core notifications (NOT-02, in-app and email by default), when a schedule falls due within its warning days or its meter passes the next due reading. | Must |
| FA-20 | **Tax book (optional).** Tax wear-and-tear classes per country are country-pack data (`api/country-packs/{KE,CD}/fixed_assets.json`, CP-01): key, method (`straight_line` or `reducing_balance`) and annual rate, effective-dated (one row per period; each year uses the row in force at its end), and the pack's `acquisition_rule` (`full_year`, or `pro_rata_months`: months in service over 12). They ship with **null rates and methods, `needs_confirmation`** until the owner confirms them from official sources (list below). Turning the tax book on for a company, and the tax book report, are refused (`tax_rates_unconfirmed`, naming the classes) while any row of a class used by the company's categories has no confirmed rate, while a category has no class, or while the acquisition rule is unconfirmed. With confirmed data the report gives per asset and year: the tax written-down value at the year's start, the allowance (half-up minor units; the remainder at the end of a straight-line class) and the value at the year's end. In the year of its disposal an asset takes no allowance and its row is marked disposed, with the base proceeds; no balancing allowance or charge is computed until the pack carries that rule. The tax book never posts journals. | Should |
| FA-21 | **Capitalisation from Procurement.** Items are flagged as assets per company (`fa_item_categories`: an item, non-stock or service only, with its default category; `item_is_stock` otherwise). When a Procurement GRN posts (`GoodsReceiptPosted`), Fixed assets' listener creates one draft asset per whole unit accepted of each flagged line (at most 50 per line; a line with a fractional quantity or more than 50 units gives one draft for the whole line), at the receipt's location, with the base value and landed cost of the line split in integer minor units by largest remainder, the line's serials one per draft, the supplier and the GRN number as reference. When a Procurement supplier invoice is approved (`SupplierInvoiceApproved`), its flagged lines whose PO line has no drafts from a GRN give drafts the same way at the invoice's base net; a GRN line whose PO line already has drafts from an invoice gives none. Each line is handled under an advisory lock on its PO line, so a GRN and an invoice of the same line never both capitalise it. A cancelled GRN (`GoodsReceiptCancelled`) archives any drafts made from it. Each receipt or invoice line creates drafts once (`fa_assets` unique by source line), whatever the retries. Nothing happens while Fixed assets is not active. Drafts are reviewed and activated by a person (FA-05). | Must |
| FA-22 | **Accounting.** Activations (except opening register assets), runs (one source per branch and cost centre), run reversals, revaluations, impairments and disposals each raise an event carrying an ACC-07 source document in base currency: a posting id (the idempotency key), the date, amounts by field and the branch, cost centre and party dimensions. Every source balances by construction. Accounting's listener posts them through the company's rules when Accounting is active (queued, idempotent by source, a closed period kept as an exception, ACC-07); its default rules for KE and CD come with the chart templates. Fixed assets keeps every posting it raised (`fa_postings`) and replays them for the Accounting backfill (ACC-29). | Must |
| FA-23 | **Custom fields** (CF-01 to CF-06): assets carry custom fields (`custom` jsonb, GIN index), entity `fixed_assets_asset`, while the module is active, in the asset form, detail, list filters and the label template. | Must |
| FA-24 | **Opening register import** (IMP-01 to IMP-03): importer `fixed_assets` takes per row: company, branch location code or name, tag (optional, numbered when empty), name, category code, acquisition and in-service dates, cost (decimal, the company's base currency), accumulated depreciation to date and the date it runs to, residual value, useful life, serial number, custodian email. Each row creates an active asset flagged `opening`, with its accumulated depreciation, that posts **no** capitalisation journal: the GL's opening balances come through Accounting's own opening balances importer (ACC-20), and the net book value by category report reconciles them. Undo archives an opening asset that nothing has used since (no run line, transfer, disposal, revaluation or work order). | Must |
| FA-25 | **Labels and barcodes.** Asset labels print through document templates (TPL-01, type `fixed_assets.label`, 58 mm roll, a page per label): tag, name, barcode (the tag), serial number, location and company; as HTML for the browser's print in a sandboxed frame or as a PDF (`fixed_assets.label.print`, at most 500 labels a print). Audited. | Must |
| FA-26 | **Reports and lists** (EXP-01, LAY-04): the asset register (search, filters by company, branch, location, category, status, custodian, saved views, export), each asset's depreciation schedule (posted lines and the projection to the end of its life with the current estimates), depreciation runs, transfers, disposals, revaluations, work orders; reports: depreciation by period (from, to; by category, branch or cost centre), additions and disposals of a period, and net book value by category at a date. Money columns are blank without `fixed_assets.value.view` (RBAC-05). | Must |
| FA-27 | **Numbering** (NUM-01): asset tags `FA-{BRANCH}-{00001}` (never reset), transfers `FAT-{BRANCH}-{YYYY}-{00001}`, disposals `FAD-{BRANCH}-{YYYY}-{00001}`, work orders `WO-{BRANCH}-{YYYY}-{00001}` (yearly). A tag typed by a person must be unique in the company (`tag_taken`). | Must |
| FA-28 | **Dashboard widgets** (LAY-01): net book value of the assets the reader may value (KPI, base currency), maintenance due within 30 days (list), assets fully depreciated still in use (count). | Should |
| FA-29 | **Scope and permissions.** Every endpoint checks `fixed_assets.*` at the asset's location (RBAC-04); company-wide actions (categories, settings, runs, reports) at the company. Ids of other tenants or out of scope answer 404; bodies naming another tenant's or company's records are refused (422). | Must |
| FA-30 | **Audit** (AUD-01): every create, update, activation, cancellation, estimate change, document upload and archive, run post and reversal, transfer, disposal, revaluation, impairment, meter reading, schedule, work order, label print and export. | Must |

## Depreciation: the arithmetic

For an asset with cost `C`, accumulated depreciation `A`, impairment `I`,
residual value `R` (all base minor units), the **net book value** is
`N = C − A − I` and the **depreciable amount** `D = max(0, N − R)`.

A charge covers a span `[f, t]`: `f` is the day after the asset's
depreciated-to date (or its in-service date), `t` the run month's end or
the disposal date.

- **Life units** (`days` convention): the life ends on `E = S + L months −
  1 day` (`S` in-service date, `L` life in months). Units of the span =
  days in `[f, min(t, E)]`; units left = days in `[f, E]`.
- **Life units** (`mid_month` convention): the life holds `2L` half
  months: one in `S`'s month, two in each month after it up to `L − 1`
  months later, one in the month `L` months after. Units of a run span =
  the half months of its months; a disposal's month counts one half (none
  in the asset's first month).
- **Straight line:** `charge = D × units ÷ units left`, half up; the span
  whose units reach the units left takes `D`.
- **Declining balance:** `db = N × rate ÷ 100 × units ÷ year units` (days
  of the span's year, or 24 half months), half up; `charge = min(D,
  max(db, straight line))`.
- **Units of production:** `charge = D × used ÷ (total − used before)`,
  half up; reaching the total takes `D`.
- A span after the end of the life (a life shortened by a change of
  estimate) takes `D` at once.
- A declining-balance span across a year end (a catch-up) is charged year
  by year, each part on its own year's length and on the book value left
  after the part before.

Every division is done once on exact integers (`BigInteger`), so the sum of
an asset's charges over its life is `C − R` exactly.

## Data model

Every table has `tenant_id` under forced RLS (TEN-01) and UUID v7 keys.
Users are referenced by `(tenant_id, id)`. Money `bigint` minor units next
to a currency; rates through the `fxSnapshot('fx')` columns
(`numeric(18,8)`). Records are never deleted (TEN-06).

| Table | Holds |
| --- | --- |
| `fa_settings` | Per company: `convention` (`days`, `mid_month`), `tax_book` |
| `fa_categories` | Company, code, name, method, useful life months, residual percent (numeric(7,4)), declining rate (numeric(7,4)), tax class, archived |
| `fa_item_categories` | Company, item, category: items flagged as assets (FA-21) |
| `fa_assets` | Tag, company, branch, location, category, name, description, status, custodian user or employee, cost centre, department, serial, acquisition and in-service dates, currency, cost, fx, base currency, base cost, supplier and reference, warranty, method, life, residual, declining rate, total units, meter at acquisition; book: cost, accumulated depreciation, impairment, revaluation surplus, revaluation losses in profit or loss, depreciated-to date; opening flag; source (`manual`, `import`, `procurement_receipt`, `procurement_invoice`), source document and line (unique), PO line; activated, disposed; `custom`; archived |
| `fa_asset_documents` | Asset, name, type, size, path, uploader, archived |
| `fa_asset_events` | The history: asset, kind (`created`, `activated`, `estimate_changed`, `transferred`, `revalued`, `impaired`, `disposed`, `reading`, `maintenance`), date, before and after values, document link, user |
| `fa_depreciation_runs` | Company, month, status (`posted`, `reversed`), convention, base currency, total, assets count, posted and reversed by and when, reversal reason |
| `fa_depreciation_lines` | Run, asset, category, branch, cost centre, span from and to, charge, units, depreciated-to before, accumulated and net book value after |
| `fa_transfers` | Number, company, asset, from and to location, branch, custodian, cost centre, date, reason, status (`pending`, `applied`, `rejected`, `cancelled`), requester, decider |
| `fa_disposals` | Number, company, asset, kind, date, currency, proceeds, fx, base proceeds, buyer, reason, status (`pending`, `approved`, `posted`, `rejected`, `cancelled`), the posted figures (catch-up depreciation, cost, accumulated, impairment, net book value, gain or loss, surplus released), the last refusal |
| `fa_revaluations` | Company, asset, kind (`revaluation`, `impairment`), date, value entered, before and after book figures, surplus change, profit or loss change, reason, poster |
| `fa_readings` | Asset, date, reading (numeric(20,6)), note |
| `fa_maintenance_schedules` | Asset, name, basis (`time`, `usage`), interval days or units, warning days, last done date and reading, next due date and reading, the due date last reminded, active, archived |
| `fa_work_orders` | Number, company, asset, schedule, title, description, status, due, assignee, vendor, completed, reading, currency, cost, fx, base cost |
| `fa_postings` | What was raised to Accounting: company, source type, document (`asset`, `run`, `disposal`, `revaluation`) and id, branch, cost centre, date, amounts, reference, reversed posting |

## Events

**Raised** (`Modules\FixedAssets\Events`, `ShouldDispatchAfterCommit`,
ids and values only), each one `fa_postings` row, `posting` = the ACC-07
source: `{posting_id, company_id, source_type, date, currency (base),
amounts (minor digit strings), dimensions {branch_id, cost_centre_id,
party_id?}, reference, link {kind, id}, reverse}`.

| Event | Source type | Amount fields | Journal it stands for |
| --- | --- | --- | --- |
| `AssetCapitalised` | `fixed_assets.capitalisation` | `cost.{category}`, and the credit `procurement` (from a GRN or invoice) or `manual` | Dr asset cost — Cr purchases (Procurement's invoice expensed the line) or the fixed asset purchases clearing account |
| `DepreciationPosted` | `fixed_assets.depreciation` | `depreciation.{category}`, `accumulated.{category}` (equal) | Dr depreciation expense — Cr accumulated depreciation |
| `AssetRevalued` | `fixed_assets.revaluation` | `accumulated.{category}` (A + I eliminated), `cost.{category}` (new value − cost, signed), `surplus` (signed), `gain`, `loss` | Dr accumulated, Dr/Cr cost — Cr/Dr surplus, Cr gain, Dr loss |
| `AssetImpaired` | `fixed_assets.impairment` | `impairment` (profit or loss), `surplus` (used), `accumulated.{category}` (= their sum) | Dr impairment loss, Dr surplus — Cr accumulated |
| `AssetDisposed` | `fixed_assets.disposal` | `cost.{category}`, `accumulated.{category}` (A + I), `proceeds`, `gain`, `loss`, `book_value`, `surplus` | Dr accumulated, Dr proceeds receivable, Dr loss — Cr cost, Cr gain (KE); or gross through book value and proceeds accounts (CD); the surplus to retained earnings |
| `DepreciationReversed` | `fixed_assets.depreciation` (`reverse`) | as the run's postings | The run's journals reversed |

A disposal's catch-up depreciation is a `fixed_assets.depreciation` source
of its own.

**Consumed:** `GoodsReceiptPosted`, `GoodsReceiptCancelled` and `SupplierInvoiceApproved`
(Procurement, FA-21; Procurement adds the lines Fixed assets needs to their
payloads: GRN lines `{line_id, order_line_id, item_id, description, qty,
base_value_minor, landed_minor, serials}` and the invoice lines'
`order_line_id` and `line_id`); `WorkflowCompleted`, `WorkflowCancelled`
(core) settle transfers and disposals.

## Permissions

| Permission | What it allows |
| --- | --- |
| `fixed_assets.asset.view`, `.manage` | Read the register, documents, history and schedules; create, edit, activate and cancel drafts, change estimates, upload documents, take readings, flag items |
| `fixed_assets.category.manage` | Categories |
| `fixed_assets.depreciation.view`, `.post` | Runs; preview, post and reverse |
| `fixed_assets.transfer.request`, `.approve` | Request and cancel transfers; act on the transfer flow |
| `fixed_assets.disposal.request`, `.approve` | Request, cancel and post disposals; act on the disposal flow |
| `fixed_assets.revaluation.post` | Revalue and impair |
| `fixed_assets.maintenance.view`, `.manage` | Schedules and work orders; reminders go to `manage` |
| `fixed_assets.label.print` | Labels |
| `fixed_assets.report.view` | Reports |
| `fixed_assets.value.view` | Costs, values and book figures (RBAC-05): without it, money fields are null in resources, lists and exports |
| `fixed_assets.settings.edit` | The company's convention and tax book |
| `fixed_assets.tax.view` | The tax book report |

Role templates (RBAC-03): **Owner** everything; **Admin** the views
(`fixed_assets.*.view`), categories, settings and labels; **Branch
Manager** assets, transfers (the default approver), disposal requests,
maintenance, labels, reports and values; **Accountant** everything but the
two `approve` permissions; **Approver** `*.approve` with the asset and
value views; **Read-only Auditor** the views. 17 permissions in all.

## API

Prefix `/api/v1`, people's tokens, every route behind
`module:fixed_assets` (403 `module_inactive`). Lists follow ADR 008. Money
in bodies is minor units as digit strings with a currency.

| Route | Permission | Notes |
| --- | --- | --- |
| `GET/PUT fixed-assets/companies/{company}/settings` | `asset.view`; `settings.edit` | FA-01, FA-20 |
| `GET/POST fixed-assets/categories`, `PATCH …/{id}`, `POST …/{id}/archive\|restore` | `asset.view`; `category.manage` | FA-02, FA-03 |
| `GET/POST fixed-assets/item-categories`, `POST …/{id}/archive` | `asset.view`; `asset.manage` | FA-21 |
| `GET/POST fixed-assets/assets`, `GET/PATCH …/{id}`, `POST …/{id}/activate\|cancel\|estimates` | `asset.*` | FA-04, FA-05, FA-11 |
| `GET …/{id}/schedule`, `GET …/{id}/history` | `asset.view` | FA-26 |
| `GET/POST …/{id}/documents`, `GET fixed-assets/documents/{id}/download`, `POST …/archive` | `asset.view`; `asset.manage` | FA-06 |
| `GET/POST …/{id}/readings` | `asset.view`; `asset.manage` | FA-18 |
| `POST …/{id}/revaluations` | `revaluation.post` | FA-15, FA-16 |
| `GET fixed-assets/revaluations` | `asset.view` | |
| `GET fixed-assets/runs`, `GET …/{id}`, `GET fixed-assets/runs/preview?company=&period=`, `POST fixed-assets/runs`, `POST …/{id}/reverse` | `depreciation.*` | FA-12, FA-13 |
| `POST fixed-assets/assets/{id}/transfers`, `GET fixed-assets/transfers`, `GET …/{id}`, `POST …/{id}/cancel` | `transfer.request`; `asset.view` | FA-14 |
| `POST fixed-assets/assets/{id}/disposals`, `GET fixed-assets/disposals`, `GET …/{id}`, `POST …/{id}/cancel\|post` | `disposal.request`; `asset.view` | FA-17 |
| `POST fixed-assets/assets/{id}/schedules`, `GET fixed-assets/schedules`, `PATCH …/{id}`, `POST …/{id}/archive` | `maintenance.*` | FA-19 |
| `POST fixed-assets/assets/{id}/work-orders`, `GET fixed-assets/work-orders`, `GET/PATCH …/{id}` | `maintenance.*` | FA-19 |
| `POST fixed-assets/labels` | `label.print` | FA-25 |
| `GET fixed-assets/reports/{depreciation\|movements\|net-book-value}` | `report.view` | FA-26 |
| `GET fixed-assets/companies/{company}/tax-book` | `tax.view` | FA-20; `?year=`; while unconfirmed it answers `status: unconfirmed` with the classes, never figures |
| `GET fixed-assets/custodians` | `asset.manage` | Users and (HR active) employees of a company |

Errors: `asset_not_draft`, `asset_not_active`, `category_archived`,
`category_other_company`, `method_terms`, `location_unknown`,
`location_other_company`, `custodian_unknown`, `custodian_both`,
`item_is_stock`, `tag_taken`, `cost_required`, `rate_unavailable`,
`currency_inactive`, `run_exists`, `run_out_of_order`, `run_not_latest`,
`run_has_later_entries`, `period_closed`, `period_depreciated`,
`convention_locked`, `transfer_pending`, `transfer_nothing`,
`disposal_pending`, `proceeds_required`, `value_not_lower`,
`reading_decreases`, `tax_rates_unconfirmed`, `too_many_labels`,
`document_type`, `document_not_open`.

## Screens (web)

Under **Fixed assets** in the sidebar: Asset register (list with filters
and saved views; form; detail with summary, book values, depreciation
schedule, history, documents, meter readings, maintenance, and the actions:
activate, change estimates, transfer, revalue, impair, dispose, print a
label), Categories (list with inline form and the accounts each event uses),
Depreciation runs (list, preview of a month with the charge per asset,
post; detail; reverse the latest), Transfers, Disposals and Revaluations
(lists and details, with the flow), Maintenance (schedules and work
orders), Labels, Reports (register, depreciation by period, additions and
disposals, net book value by category, the tax book), and the company
settings with the items flagged as assets. Design-system components and
tokens only, en/fr.

## Integrations

- **Fixed assets → Accounting** (both active): the events above. Default
  rules come with the KE and CD chart templates (new accounts below);
  `fixed_assets.*` source types are offered on the posting rules screen.
  KE: PPE 1510, accumulated depreciation 1520, depreciation 6500, purchases
  5200 (where Procurement's invoice puts non-stock lines), fixed asset
  purchases clearing 2150, revaluation surplus 3400, impairment losses 6510,
  disposal receivable 1260, gain on disposal 4920, loss on disposal 7400,
  other income 4900, retained earnings 3200. CD (SYSCOHADA): equipment
  244, depreciation 284, depreciation charges 681, purchases 601, suppliers
  of fixed assets 481, revaluation surplus 106, impairment charges 691,
  receivables on disposals 485, book value of assets sold 811, proceeds of
  disposals 821, other income 758, retained earnings 121.
- **Procurement → Fixed assets** (both active): FA-21.
- **HR → Fixed assets**: `Modules\HR\Contracts\EmployeeDirectory` (id,
  name, number, company, status) for custodians, only while HR is active.
- **Alone**: without Accounting nothing posts, every figure is still kept;
  without Procurement assets are created by hand or imported; without HR
  custodians are users.
- **Core**: places (TEN-), users, parties, items, dimensions (MD-),
  currencies and rates (CUR-), workflow and approvals (WF-, APR-),
  numbering (NUM-01), templates (TPL-), custom fields (CF-), lists and
  exports (LAY-04, EXP-01), imports (IMP-), dashboards (LAY-01),
  notifications (NOT-02), audit (AUD-).

## Tax data the owner must confirm (FA-20)

`api/country-packs/KE/fixed_assets.json` and
`api/country-packs/CD/fixed_assets.json` hold class keys only, every
method and rate null and `needs_confirmation: true`. Nothing is guessed.
The owner must confirm, from official sources, with legal references and
effective dates:

- **KE** (Income Tax Act, Second Schedule, wear and tear and investment
  deductions): the official classes and which assets fall in each
  (buildings, machinery, motor vehicles, computers and peripherals,
  furniture and fittings, telecommunication equipment, software and
  intangibles, farm works, others); for each class the method (straight
  line or reducing balance) and the annual rate; whether an investment
  deduction applies in the first year and at what rate; the treatment in
  the year of acquisition (`acquisition_rule`: `full_year` or
  `pro_rata_months`) and on disposal (balancing allowance or charge).
- **CD** (Code des impôts, amortissements fiscalement déductibles): the
  classes (constructions, matériel et outillage, matériel de transport,
  mobilier et matériel de bureau, matériel informatique, others); for each
  the method (linéaire or dégressif and its coefficients) and the rate; the
  year-of-acquisition rule (`acquisition_rule`); the treatment in the year
  of disposal; any accelerated or exceptional depreciation.
- For both: until the disposal rule is confirmed the tax book gives no
  allowance in the year of disposal and computes no balancing adjustment.
- For both: whether land is ever allowed (expected not), and how a
  revaluation affects the tax value (expected not at all).

## Decisions taken while building (no owner input asked)

1. **Registry module `fixed_assets`**, sold under the existing catalogue
   key `fixed_assets` (per company).
2. **Categories name no account**: amounts are keyed by category id and
   Accounting's posting rules map them (ACC-24), so the chart stays
   Accounting's alone (architecture rule 1).
3. **Opening register assets post nothing to the ledger**: Accounting's
   opening balances importer brings the balances; posting them again would
   count them twice.
4. **Capitalisation from Procurement credits purchases**: Procurement's
   supplier invoice debits non-stock lines to purchases, so the asset's
   capitalisation moves that cost to the asset account; any difference
   between the GRN value and the invoice stays in purchases (price
   variance). Only non-stock and service items are flagged as assets: a
   stock item would be in Inventory and the stock ledger.
5. **Declining balance switches to straight line** when straight line
   gives more, so every method ends exactly at the residual value.
6. **Revaluation uses the elimination method** (accumulated depreciation
   eliminated against cost) and keeps the residual value; the surplus is
   released to retained earnings on disposal, not year by year.
7. **Work order costs post nothing**; the vendor bills through Accounting.
8. **One run per company per month, in order**; a run catches up assets
   activated after earlier runs.
9. **Transfers post nothing**; branch and cost centre dimensions follow the
   asset from the next charge.
10. **Accounting's closed periods are read through a contract**
    (`Modules\Accounting\Contracts\ClosedPeriods`), only while Accounting is
    active; Accounting still refuses any journal in a closed period.
11. **The net book value report is as of now**; the depreciation and movements reports take a period. A value at a past date is left for later.
12. **A disposal dated on the last charged day** takes no extra depreciation; by days the disposal day itself is depreciated.
13. **A revaluation under `mid_month` catches up to the end of the month before its date**: half months count whole months, so the revaluation month is charged by the next run on the new value.
14. **A PO line is capitalised from the first of its GRN or invoice**; the other adds nothing.
15. **A `mid_month` opening import needs a month-end depreciated-to date** (`depreciated_to_month_end`).
16. **The custodians list is open to whoever manages assets anywhere in the company** (at it, a branch or a location), since they choose custodians.
17. **Labels print only for assets where the reader holds `fixed_assets.label.print`** at the asset's location, not merely somewhere.

## Open questions

1. Should a revaluation or disposal require approval above an amount
   (WF-05 conditions on the base amount)? Today disposals always do,
   revaluations never.
2. Should the surplus be transferred to retained earnings yearly (the
   difference between depreciation on the revalued amount and on cost)?
3. Should component accounting (parts of an asset with their own lives) be
   supported?
4. Should work order costs be capitalised when they extend an asset's life?

## Status (stage 7D)

| ID | Status |
| --- | --- |
| FA-01 to FA-30 | Built and tested on `feat/fixed-assets` (modules/FixedAssets/tests: DepreciationMath, Assets, Runs, Movements, AccountingPosting, ProcurementCapitalisation, TaxBook, Maintenance, LabelsReportsImport, RoleTemplates, ReviewFixes; Accounting's ChartCodes; the isolation suite; web FixedAssets.test.jsx) |
| FA-20 | Built; computes nothing until the owner confirms the KE and CD classes and the year-of-acquisition rule (list above) |

## Deferred

- Leases (IFRS 16 right-of-use assets).
- Component depreciation; capitalised improvements.
- Asset counts (physical verification by scanning labels).
- Transfers between companies of the tenant.
- Insurance policies and claims.
